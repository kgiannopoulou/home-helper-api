#pragma once

#include <chrono>
#include <cstdint>
#include <map>
#include <memory>
#include <optional>
#include <stdexcept>
#include <string>
#include <utility>
#include <vector>

namespace hha::net {

struct Url {
    std::string scheme;  // "http" or "https", lower case
    std::string host;
    std::uint16_t port = 0;
    std::string path = "/";  // with the query, if any

    /// Only http and https; nullopt for anything else or a malformed URL.
    static std::optional<Url> parse(const std::string& text);

    bool isHttps() const { return scheme == "https"; }
    /// localhost, 127.0.0.0/8 and ::1: the only places plain HTTP is allowed
    bool isLoopback() const;
    std::string origin() const;  // scheme://host[:port]
};

struct HttpRequest {
    std::string method = "GET";
    std::string url;
    std::vector<std::pair<std::string, std::string>> headers;
    std::string body;
    std::chrono::milliseconds timeout{15'000};
    /// Responses bigger than this fail instead of filling the memory
    std::size_t maxResponseBytes = 4 * 1024 * 1024;
    /// Downloads may follow redirects (to https only); API calls never do
    bool followRedirects = false;
};

struct HttpResponse {
    int status = 0;
    /// Header names in lower case
    std::map<std::string, std::string> headers;
    std::string body;

    std::optional<std::string> header(const std::string& lowerCaseName) const;
};

/// The request never got an answer: DNS, connection refused, TLS, timeout,
/// or the response was too big. The agent counts these as "offline".
class TransportError : public std::runtime_error {
public:
    using std::runtime_error::runtime_error;
};

class HttpTransport {
public:
    virtual ~HttpTransport() = default;
    /// An HTTP status (even 500) is a response; only a missing response throws TransportError.
    virtual HttpResponse send(const HttpRequest& request) = 0;
};

struct TransportOptions {
    std::string userAgent;
    /// libcurl only: a PEM bundle instead of the system's certificates
    std::optional<std::string> caFile;
};

/// WinHTTP on Windows (system certificates and proxy settings), libcurl elsewhere.
std::unique_ptr<HttpTransport> makeSystemTransport(const TransportOptions& options);

}  // namespace hha::net
