#include "agent/enrollment.hpp"

#include "core/version.hpp"

#include <cctype>
#include <stdexcept>

namespace hha::agent {

std::string normalizeCode(const std::string& code) {
    std::string letters;
    for (const char c : code) {
        if (std::isalnum(static_cast<unsigned char>(c))) {
            letters.push_back(static_cast<char>(std::toupper(static_cast<unsigned char>(c))));
        } else if (c != '-' && c != ' ') {
            throw std::invalid_argument("a pairing code has letters and digits only, like K7QM-2XPA");
        }
    }
    if (letters.size() != 8) throw std::invalid_argument("a pairing code has 8 characters, like K7QM-2XPA");
    return letters.substr(0, 4) + "-" + letters.substr(4);
}

net::Credentials enroll(net::ApiClient& client, const std::string& serverUrl, const EnrollRequest& request) {
    nlohmann::json body = {
        {"code", normalizeCode(request.code)},
        {"hostname", request.hostname},
        {"platform", std::string(version::platform())},
        {"version", std::string(version::kVersion)},
        {"git_sha", std::string(version::kGitSha)},
    };
    if (!request.name.empty()) body["name"] = request.name;

    const auto reply = client.enroll(body);
    if (!reply.is_object() || !reply.contains("agent_id") || !reply.contains("secret")) {
        throw std::runtime_error("the server's enrollment reply is missing the agent id or secret");
    }
    const auto secret = crypto::fromHex(reply["secret"].get<std::string>());
    if (!secret || secret->size() != 32) throw std::runtime_error("the server sent a secret that is not 32 bytes");

    net::Credentials credentials;
    credentials.serverUrl = serverUrl;
    credentials.agentId = reply["agent_id"].get<std::string>();
    credentials.secret = *secret;
    if (reply.contains("household") && reply["household"].is_object()) {
        credentials.household = reply["household"].value("name", "");
    }
    return credentials;
}

}  // namespace hha::agent
