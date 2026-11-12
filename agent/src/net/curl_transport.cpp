// libcurl on Linux (and macOS): the distribution's OpenSSL and CA bundle.
#include "net/http.hpp"

#include "core/strings.hpp"

#include <curl/curl.h>

#include <mutex>

namespace hha::net {

namespace {

struct Received {
    HttpResponse response;
    std::size_t limit = 0;
    bool tooBig = false;
};

std::size_t onBody(char* data, std::size_t size, std::size_t count, void* user) {
    auto* received = static_cast<Received*>(user);
    const auto bytes = size * count;
    if (received->response.body.size() + bytes > received->limit) {
        received->tooBig = true;
        return 0;  // makes curl stop with CURLE_WRITE_ERROR
    }
    received->response.body.append(data, bytes);
    return bytes;
}

std::size_t onHeader(char* data, std::size_t size, std::size_t count, void* user) {
    auto* received = static_cast<Received*>(user);
    const auto bytes = size * count;
    const auto line = strings::trim(std::string_view(data, bytes));
    if (strings::startsWith(line, "HTTP/")) {
        received->response.headers.clear();  // a new response after a redirect or 100 Continue
    } else if (const auto colon = line.find(':'); colon != std::string_view::npos) {
        received->response.headers[strings::toLower(strings::trim(line.substr(0, colon)))] =
            std::string(strings::trim(line.substr(colon + 1)));
    }
    return bytes;
}

class CurlTransport final : public HttpTransport {
public:
    explicit CurlTransport(const TransportOptions& options) : options_(options) {
        static std::once_flag once;
        std::call_once(once, [] { curl_global_init(CURL_GLOBAL_DEFAULT); });
    }

    HttpResponse send(const HttpRequest& request) override {
        CURL* curl = curl_easy_init();
        if (!curl) throw TransportError("curl_easy_init failed");
        struct Cleanup {
            CURL* c;
            curl_slist* h = nullptr;
            ~Cleanup() {
                curl_slist_free_all(h);
                curl_easy_cleanup(c);
            }
        } cleanup{curl};

        Received received;
        received.limit = request.maxResponseBytes;

        curl_easy_setopt(curl, CURLOPT_URL, request.url.c_str());
        curl_easy_setopt(curl, CURLOPT_PROTOCOLS_STR, "http,https");
        curl_easy_setopt(curl, CURLOPT_REDIR_PROTOCOLS_STR, "https");
        curl_easy_setopt(curl, CURLOPT_FOLLOWLOCATION, request.followRedirects ? 1L : 0L);
        curl_easy_setopt(curl, CURLOPT_MAXREDIRS, 5L);
        curl_easy_setopt(curl, CURLOPT_NOSIGNAL, 1L);
        curl_easy_setopt(curl, CURLOPT_TIMEOUT_MS, static_cast<long>(request.timeout.count()));
        curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT_MS, static_cast<long>(request.timeout.count()));
        curl_easy_setopt(curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        curl_easy_setopt(curl, CURLOPT_USERAGENT, options_.userAgent.c_str());
        if (options_.caFile) curl_easy_setopt(curl, CURLOPT_CAINFO, options_.caFile->c_str());
        curl_easy_setopt(curl, CURLOPT_CUSTOMREQUEST, request.method.c_str());
        if (request.method != "GET") {
            curl_easy_setopt(curl, CURLOPT_POSTFIELDS, request.body.data());
            curl_easy_setopt(curl, CURLOPT_POSTFIELDSIZE_LARGE, static_cast<curl_off_t>(request.body.size()));
        }
        for (const auto& [name, value] : request.headers) {
            cleanup.h = curl_slist_append(cleanup.h, (name + ": " + value).c_str());
        }
        cleanup.h = curl_slist_append(cleanup.h, "Expect:");  // no 100-continue round trip
        curl_easy_setopt(curl, CURLOPT_HTTPHEADER, cleanup.h);
        curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, onBody);
        curl_easy_setopt(curl, CURLOPT_WRITEDATA, &received);
        curl_easy_setopt(curl, CURLOPT_HEADERFUNCTION, onHeader);
        curl_easy_setopt(curl, CURLOPT_HEADERDATA, &received);

        const CURLcode code = curl_easy_perform(curl);
        if (received.tooBig) {
            throw TransportError("response larger than " + std::to_string(request.maxResponseBytes) + " bytes");
        }
        if (code != CURLE_OK) {
            throw TransportError(curl_easy_strerror(code));
        }
        long status = 0;
        curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &status);
        received.response.status = static_cast<int>(status);
        return std::move(received.response);
    }

private:
    TransportOptions options_;
};

}  // namespace

std::unique_ptr<HttpTransport> makeSystemTransport(const TransportOptions& options) {
    return std::make_unique<CurlTransport>(options);
}

}  // namespace hha::net
