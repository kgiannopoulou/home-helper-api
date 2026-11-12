#include "net/api_client.hpp"

#include "core/version.hpp"
#include "net/signing.hpp"

#include <spdlog/spdlog.h>

#include <chrono>

namespace hha::net {

namespace {

constexpr std::string_view kApiPrefix = "/api/agent/v1/";

std::int64_t systemSeconds() {
    return std::chrono::duration_cast<std::chrono::seconds>(std::chrono::system_clock::now().time_since_epoch()).count();
}

std::string errorCode(const json& body) {
    if (body.is_object() && body.contains("error") && body["error"].is_string()) {
        return body["error"].get<std::string>();
    }
    return {};
}

std::string errorMessage(int status, const json& body) {
    if (body.is_object() && body.contains("message") && body["message"].is_string()) {
        return "HTTP " + std::to_string(status) + ": " + body["message"].get<std::string>();
    }
    return "HTTP " + std::to_string(status);
}

}  // namespace

std::string dumpJson(const json& value) {
    return value.dump(-1, ' ', false, json::error_handler_t::replace);
}

ApiClient::ApiClient(Options options, HttpTransport& transport, Clock clock)
    : options_(std::move(options)), transport_(transport), clock_(clock ? std::move(clock) : Clock(systemSeconds)) {
    auto url = Url::parse(options_.serverUrl);
    if (!url) {
        throw std::invalid_argument("server_url is not an http(s) URL: " + options_.serverUrl);
    }
    if (!url->isHttps() && !url->isLoopback() && !options_.allowInsecureHttp) {
        throw std::invalid_argument("server_url must use https:// (plain http is only allowed to localhost)");
    }
    if (const auto q = url->path.find('?'); q != std::string::npos) {
        url->path = url->path.substr(0, q);
    }
    while (!url->path.empty() && url->path.back() == '/') {
        url->path.pop_back();
    }
    base_ = *url;
}

void ApiClient::setCredentials(Credentials credentials) {
    credentials_ = std::move(credentials);
}

std::string ApiClient::urlFor(std::string_view endpoint) const {
    return base_.origin() + base_.path + std::string(kApiPrefix) + std::string(endpoint);
}

std::string ApiClient::userAgent() {
    return "home-helper-agent/" + std::string(version::kVersion) + " (" + std::string(version::platform()) + ")";
}

json ApiClient::enroll(const json& body) {
    return send("POST", "enroll", dumpJson(body), false, false);
}

json ApiClient::post(std::string_view endpoint, const json& body) {
    return send("POST", endpoint, dumpJson(body), true, false);
}

json ApiClient::get(std::string_view endpoint) {
    return send("GET", endpoint, "", true, false);
}

json ApiClient::send(const std::string& method, std::string_view endpoint, const std::string& body, bool sign,
                     bool retried) {
    if (sign && !credentials_) {
        throw std::logic_error("this computer is not enrolled yet");
    }

    HttpRequest request;
    request.method = method;
    request.url = urlFor(endpoint);
    request.body = body;
    request.timeout = options_.timeout;
    request.headers = {{"Accept", "application/json"}, {"User-Agent", userAgent()}};
    if (method != "GET") {
        request.headers.emplace_back("Content-Type", "application/json");
    }

    std::string nonce;
    if (sign) {
        nonce = crypto::randomHex(16);
        const auto path = base_.path + std::string(kApiPrefix) + std::string(endpoint);
        const auto sig = signRequest(credentials_->secret, credentials_->agentId, method, path, body,
                                     clock_() + clockOffset_.load(), nonce);
        request.headers.emplace_back(std::string(kHeaderAgent), sig.agentId);
        request.headers.emplace_back(std::string(kHeaderTimestamp), sig.timestamp);
        request.headers.emplace_back(std::string(kHeaderNonce), sig.nonce);
        request.headers.emplace_back(std::string(kHeaderSignature), sig.signature);
    }

    const auto response = transport_.send(request);  // TransportError propagates: offline
    json reply = nullptr;
    if (!response.body.empty()) {
        reply = json::parse(response.body, nullptr, false);
        if (reply.is_discarded()) reply = nullptr;
    }

    const bool success = response.status >= 200 && response.status < 300;
    if (success && sign) {
        // A successful answer is acted on, so it must be the server's, and an answer to this request
        const auto signature = response.header("x-hh-signature");
        if (!signature || !verifyResponse(credentials_->secret, response.status, nonce, response.body, *signature)) {
            throw ApiError(ApiError::Kind::BadSignature, response.status, "bad_signature",
                           "the response to " + std::string(endpoint) + " is not signed by the server");
        }
    }
    if (success) {
        return reply;
    }

    const auto code = errorCode(reply);
    if (response.status == 401 && code == "clock_skew" && !retried && reply.contains("server_time") &&
        reply["server_time"].is_number_integer()) {
        const auto offset = reply["server_time"].get<std::int64_t>() - clock_();
        spdlog::warn("Clock is {} s off from the server; correcting and retrying", offset);
        clockOffset_ = offset;
        return send(method, endpoint, body, sign, true);
    }

    auto kind = ApiError::Kind::Rejected;
    if (response.status == 401) {
        kind = code == "agent_revoked" ? ApiError::Kind::Revoked : ApiError::Kind::Unauthorized;
    } else if (response.status == 429) {
        kind = ApiError::Kind::RateLimited;
    } else if (response.status >= 500) {
        kind = ApiError::Kind::Server;
    }
    throw ApiError(kind, response.status, code, errorMessage(response.status, reply));
}

}  // namespace hha::net
