// WinHTTP: TLS with the Windows certificate store, the system proxy, and no
// extra library to ship or patch.
#include "net/http.hpp"

#include "core/strings.hpp"

#include <windows.h>
#include <winhttp.h>

#include <string>

namespace hha::net {

namespace {

struct Handle {
    HINTERNET h = nullptr;
    Handle() = default;
    explicit Handle(HINTERNET handle) : h(handle) {}
    ~Handle() {
        if (h) WinHttpCloseHandle(h);
    }
    Handle(const Handle&) = delete;
    Handle& operator=(const Handle&) = delete;
    explicit operator bool() const { return h != nullptr; }
};

[[noreturn]] void fail(const char* what) {
    const DWORD code = GetLastError();
    std::string reason;
    switch (code) {
        case ERROR_WINHTTP_TIMEOUT: reason = "timed out"; break;
        case ERROR_WINHTTP_CANNOT_CONNECT: reason = "cannot connect"; break;
        case ERROR_WINHTTP_NAME_NOT_RESOLVED: reason = "host not found"; break;
        case ERROR_WINHTTP_CONNECTION_ERROR: reason = "connection reset"; break;
        case ERROR_WINHTTP_SECURE_FAILURE: reason = "TLS certificate rejected"; break;
        default: reason = "error " + std::to_string(code); break;
    }
    throw TransportError(std::string(what) + ": " + reason);
}

std::map<std::string, std::string> parseHeaders(const std::wstring& raw) {
    std::map<std::string, std::string> headers;
    const auto text = strings::narrow(raw);
    bool first = true;  // the status line
    for (auto line : strings::split(text, '\n')) {
        line = strings::trim(line);
        if (first) {
            first = false;
            continue;
        }
        const auto colon = line.find(':');
        if (line.empty() || colon == std::string_view::npos) continue;
        headers[strings::toLower(strings::trim(line.substr(0, colon)))] = std::string(strings::trim(line.substr(colon + 1)));
    }
    return headers;
}

class WinHttpTransport final : public HttpTransport {
public:
    explicit WinHttpTransport(const TransportOptions& options)
        : session_(WinHttpOpen(strings::widen(options.userAgent).c_str(), WINHTTP_ACCESS_TYPE_AUTOMATIC_PROXY,
                               WINHTTP_NO_PROXY_NAME, WINHTTP_NO_PROXY_BYPASS, 0)) {
        if (!session_) fail("WinHttpOpen");
        // TLS 1.2 and 1.3 only
        DWORD protocols = WINHTTP_FLAG_SECURE_PROTOCOL_TLS1_2 | WINHTTP_FLAG_SECURE_PROTOCOL_TLS1_3;
        if (!WinHttpSetOption(session_.h, WINHTTP_OPTION_SECURE_PROTOCOLS, &protocols, sizeof(protocols))) {
            protocols = WINHTTP_FLAG_SECURE_PROTOCOL_TLS1_2;  // before Windows 11 / Server 2022
            WinHttpSetOption(session_.h, WINHTTP_OPTION_SECURE_PROTOCOLS, &protocols, sizeof(protocols));
        }
    }

    HttpResponse send(const HttpRequest& request) override {
        const auto url = Url::parse(request.url);
        if (!url) throw TransportError("not an http(s) URL: " + request.url);

        Handle connection(WinHttpConnect(session_.h, strings::widen(url->host).c_str(), url->port, 0));
        if (!connection) fail("connect");

        Handle req(WinHttpOpenRequest(connection.h, strings::widen(request.method).c_str(),
                                      strings::widen(url->path).c_str(), nullptr, WINHTTP_NO_REFERER,
                                      WINHTTP_DEFAULT_ACCEPT_TYPES, url->isHttps() ? WINHTTP_FLAG_SECURE : 0));
        if (!req) fail("open request");

        const int ms = static_cast<int>(request.timeout.count());
        WinHttpSetTimeouts(req.h, ms, ms, ms, ms);

        DWORD redirects = request.followRedirects ? WINHTTP_OPTION_REDIRECT_POLICY_DISALLOW_HTTPS_TO_HTTP
                                                  : WINHTTP_OPTION_REDIRECT_POLICY_NEVER;
        WinHttpSetOption(req.h, WINHTTP_OPTION_REDIRECT_POLICY, &redirects, sizeof(redirects));

        std::wstring headers;
        for (const auto& [name, value] : request.headers) {
            headers += strings::widen(name + ": " + value + "\r\n");
        }
        if (!headers.empty() &&
            !WinHttpAddRequestHeaders(req.h, headers.c_str(), static_cast<DWORD>(-1L), WINHTTP_ADDREQ_FLAG_ADD)) {
            fail("headers");
        }

        auto* body = request.body.empty() ? WINHTTP_NO_REQUEST_DATA : const_cast<char*>(request.body.data());
        const auto length = static_cast<DWORD>(request.body.size());
        if (!WinHttpSendRequest(req.h, WINHTTP_NO_ADDITIONAL_HEADERS, 0, body, length, length, 0)) fail("send");
        if (!WinHttpReceiveResponse(req.h, nullptr)) fail("receive");

        HttpResponse response;
        DWORD status = 0;
        DWORD size = sizeof(status);
        if (!WinHttpQueryHeaders(req.h, WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER, WINHTTP_HEADER_NAME_BY_INDEX,
                                 &status, &size, WINHTTP_NO_HEADER_INDEX)) {
            fail("status");
        }
        response.status = static_cast<int>(status);

        DWORD headerBytes = 0;
        WinHttpQueryHeaders(req.h, WINHTTP_QUERY_RAW_HEADERS_CRLF, WINHTTP_HEADER_NAME_BY_INDEX, nullptr, &headerBytes,
                            WINHTTP_NO_HEADER_INDEX);
        if (headerBytes > 0) {
            std::wstring raw(headerBytes / sizeof(wchar_t), L'\0');
            if (WinHttpQueryHeaders(req.h, WINHTTP_QUERY_RAW_HEADERS_CRLF, WINHTTP_HEADER_NAME_BY_INDEX, raw.data(),
                                    &headerBytes, WINHTTP_NO_HEADER_INDEX)) {
                raw.resize(headerBytes / sizeof(wchar_t));
                response.headers = parseHeaders(raw);
            }
        }

        while (true) {
            DWORD available = 0;
            if (!WinHttpQueryDataAvailable(req.h, &available)) fail("read");
            if (available == 0) break;
            if (response.body.size() + available > request.maxResponseBytes) {
                throw TransportError("response larger than " + std::to_string(request.maxResponseBytes) + " bytes");
            }
            const auto offset = response.body.size();
            response.body.resize(offset + available);
            DWORD read = 0;
            if (!WinHttpReadData(req.h, response.body.data() + offset, available, &read)) fail("read");
            response.body.resize(offset + read);
        }
        return response;
    }

private:
    Handle session_;
};

}  // namespace

std::unique_ptr<HttpTransport> makeSystemTransport(const TransportOptions& options) {
    return std::make_unique<WinHttpTransport>(options);
}

}  // namespace hha::net
