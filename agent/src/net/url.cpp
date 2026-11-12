#include "net/http.hpp"

#include "core/strings.hpp"

#include <charconv>

namespace hha::net {

std::optional<Url> Url::parse(const std::string& text) {
    const auto schemeEnd = text.find("://");
    if (schemeEnd == std::string::npos) {
        return std::nullopt;
    }
    Url url;
    url.scheme = strings::toLower(text.substr(0, schemeEnd));
    if (url.scheme != "http" && url.scheme != "https") {
        return std::nullopt;
    }

    const auto rest = std::string_view(text).substr(schemeEnd + 3);
    const auto pathStart = rest.find_first_of("/?#");
    auto authority = rest.substr(0, pathStart);
    if (pathStart != std::string_view::npos) {
        auto path = rest.substr(pathStart);
        if (const auto hash = path.find('#'); hash != std::string_view::npos) {
            path = path.substr(0, hash);
        }
        url.path = path.empty() || path.front() != '/' ? "/" + std::string(path) : std::string(path);
    }
    if (authority.empty() || authority.find('@') != std::string_view::npos) {
        return std::nullopt;  // credentials in a URL are never used
    }

    std::string_view portText;
    if (authority.front() == '[') {  // [::1]:8080
        const auto close = authority.find(']');
        if (close == std::string_view::npos) return std::nullopt;
        url.host = std::string(authority.substr(1, close - 1));
        const auto after = authority.substr(close + 1);
        if (!after.empty()) {
            if (after.front() != ':') return std::nullopt;
            portText = after.substr(1);
        }
    } else {
        const auto colon = authority.rfind(':');
        url.host = std::string(authority.substr(0, colon));
        if (colon != std::string_view::npos) portText = authority.substr(colon + 1);
    }
    if (url.host.empty() || !strings::isPrintable(url.host) || url.host.find(' ') != std::string::npos) {
        return std::nullopt;
    }
    url.host = strings::toLower(url.host);

    if (portText.empty()) {
        url.port = url.isHttps() ? 443 : 80;
    } else {
        unsigned value = 0;
        const auto [ptr, ec] = std::from_chars(portText.data(), portText.data() + portText.size(), value);
        if (ec != std::errc{} || ptr != portText.data() + portText.size() || value == 0 || value > 65535) {
            return std::nullopt;
        }
        url.port = static_cast<std::uint16_t>(value);
    }
    return url;
}

bool Url::isLoopback() const {
    return host == "localhost" || host == "::1" || strings::startsWith(host, "127.");
}

std::string Url::origin() const {
    const bool defaultPort = (isHttps() && port == 443) || (!isHttps() && port == 80);
    const auto hostPart = host.find(':') != std::string::npos ? "[" + host + "]" : host;
    return scheme + "://" + hostPart + (defaultPort ? "" : ":" + std::to_string(port));
}

std::optional<std::string> HttpResponse::header(const std::string& lowerCaseName) const {
    if (const auto it = headers.find(lowerCaseName); it != headers.end()) {
        return it->second;
    }
    return std::nullopt;
}

}  // namespace hha::net
