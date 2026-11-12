#pragma once

#include "crypto/crypto.hpp"
#include "net/http.hpp"
#include "net/signing.hpp"

#include <functional>
#include <mutex>
#include <optional>
#include <string>
#include <vector>

namespace hha::test {

inline std::optional<std::string> requestHeader(const net::HttpRequest& request, std::string_view name) {
    for (const auto& [key, value] : request.headers) {
        if (key == name) return value;
    }
    return std::nullopt;
}

/// An HttpTransport that answers from a function and remembers every request.
class FakeTransport final : public net::HttpTransport {
public:
    using Handler = std::function<net::HttpResponse(const net::HttpRequest&)>;

    explicit FakeTransport(Handler handler = {}) : handler_(std::move(handler)) {}

    void setHandler(Handler handler) {
        std::lock_guard lock(mutex_);
        handler_ = std::move(handler);
    }

    /// Every request fails with a TransportError, as if the server were unreachable.
    void goOffline(bool offline = true) {
        std::lock_guard lock(mutex_);
        offline_ = offline;
    }

    net::HttpResponse send(const net::HttpRequest& request) override {
        Handler handler;
        {
            std::lock_guard lock(mutex_);
            requests_.push_back(request);
            if (offline_) throw net::TransportError("connect: cannot connect");
            handler = handler_;
        }
        if (!handler) return {404, {}, R"({"error":"not_found"})"};
        return handler(request);
    }

    std::vector<net::HttpRequest> requests() const {
        std::lock_guard lock(mutex_);
        return requests_;
    }

    std::size_t count() const {
        std::lock_guard lock(mutex_);
        return requests_.size();
    }

private:
    mutable std::mutex mutex_;
    Handler handler_;
    std::vector<net::HttpRequest> requests_;
    bool offline_ = false;
};

/// A response the way the server would sign it for this request.
inline net::HttpResponse signedResponse(const crypto::Bytes& secret, const net::HttpRequest& request, int status,
                                        std::string body) {
    const auto nonce = requestHeader(request, net::kHeaderNonce).value_or("");
    net::HttpResponse response{status, {}, std::move(body)};
    response.headers["x-hh-signature"] =
        crypto::hmacSha512Hex(secret, net::canonicalResponse(status, nonce, response.body));
    return response;
}

}  // namespace hha::test
