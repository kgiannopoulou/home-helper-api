#pragma once

#include "crypto/crypto.hpp"
#include "net/http.hpp"

#include <nlohmann/json.hpp>

#include <atomic>
#include <chrono>
#include <cstdint>
#include <functional>
#include <optional>
#include <stdexcept>
#include <string>
#include <string_view>

namespace hha::net {

using nlohmann::json;

/// What enrollment gave this computer; saved by agent::CredentialStore.
struct Credentials {
    std::string serverUrl;
    std::string agentId;
    crypto::Bytes secret;
    std::string household;
};

/// The server answered, but not with success.
class ApiError : public std::runtime_error {
public:
    enum class Kind {
        Unauthorized,   // 401: unknown agent, bad signature, replay
        Revoked,        // 401 agent_revoked: the owner removed this computer
        Rejected,       // other 4xx: the request itself was wrong
        RateLimited,    // 429
        Server,         // 5xx
        BadSignature,   // a 2xx without a valid response signature: never trusted
    };

    ApiError(Kind kind, int status, std::string code, const std::string& message)
        : std::runtime_error(message), kind_(kind), status_(status), code_(std::move(code)) {}

    Kind kind() const { return kind_; }
    int status() const { return status_; }
    const std::string& code() const { return code_; }
    /// Worth retrying later: rate limits and server errors
    bool transient() const { return kind_ == Kind::RateLimited || kind_ == Kind::Server; }

private:
    Kind kind_;
    int status_;
    std::string code_;
};

/// The agent API, /api/agent/v1/… (docs/protocol.md). Signs every request
/// after enrollment and checks the signature of every response.
class ApiClient {
public:
    using Clock = std::function<std::int64_t()>;  // unix seconds

    struct Options {
        std::string serverUrl;
        /// Plain HTTP to something other than localhost (a lab, never production)
        bool allowInsecureHttp = false;
        std::chrono::milliseconds timeout{15'000};
    };

    /// Throws std::invalid_argument for a URL that is not https:// (or http:// to loopback).
    ApiClient(Options options, HttpTransport& transport, Clock clock = {});

    void setCredentials(Credentials credentials);
    bool hasCredentials() const { return credentials_.has_value(); }
    const std::optional<Credentials>& credentials() const { return credentials_; }

    /// POST /enroll, unsigned: the code is the proof.
    json enroll(const json& body);

    /// Signed POST of a JSON body; returns the JSON reply (null for 204).
    json post(std::string_view endpoint, const json& body);
    /// Signed GET; returns the JSON reply (null for 204).
    json get(std::string_view endpoint);

    /// Seconds to add to the local clock, learned from the server after a
    /// clock_skew rejection (a laptop that slept, an RTC without a battery).
    std::int64_t clockOffset() const { return clockOffset_.load(); }

    /// The full URL of an endpoint, e.g. "heartbeat" → https://…/api/agent/v1/heartbeat
    std::string urlFor(std::string_view endpoint) const;

    static std::string userAgent();

private:
    json send(const std::string& method, std::string_view endpoint, const std::string& body, bool sign, bool retried);

    Options options_;
    Url base_;
    HttpTransport& transport_;
    Clock clock_;
    std::optional<Credentials> credentials_;
    std::atomic<std::int64_t> clockOffset_{0};
};

/// JSON text for the wire. Invalid UTF-8 (a process's output in the OEM
/// code page, say) becomes U+FFFD instead of throwing.
std::string dumpJson(const json& value);

}  // namespace hha::net
