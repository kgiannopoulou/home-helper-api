#include "agent/heartbeat.hpp"

#include "core/version.hpp"

#include <algorithm>

namespace hha::agent {

using nlohmann::json;

namespace {

std::string str(const json& j, const char* key) {
    return j.contains(key) && j[key].is_string() ? j[key].get<std::string>() : std::string();
}

bool boolean(const json& j, const char* key) {
    return j.contains(key) && j[key].is_boolean() && j[key].get<bool>();
}

}  // namespace

HeartbeatReply HeartbeatReply::parse(const json& body) {
    HeartbeatReply reply;
    if (!body.is_object()) {
        reply.problems.push_back("the reply is not a JSON object");
        return reply;
    }
    if (body.contains("server_time") && body["server_time"].is_number_integer()) {
        reply.serverTime = body["server_time"].get<std::int64_t>();
    }
    if (body.contains("heartbeat_seconds") && body["heartbeat_seconds"].is_number_integer()) {
        reply.heartbeat = std::chrono::seconds(std::clamp<std::int64_t>(body["heartbeat_seconds"].get<std::int64_t>(), 5, 3600));
    }
    reply.inventoryWanted = boolean(body, "inventory_wanted");

    if (body.contains("commands") && body["commands"].is_array()) {
        for (const auto& c : body["commands"]) {
            CommandRequest request;
            if (!c.is_object() || str(c, "id").empty() || str(c, "key").empty()) {
                reply.problems.push_back("a command without an id or key");
                continue;
            }
            request.id = str(c, "id");
            request.key = str(c, "key");
            if (c.contains("params") && c["params"].is_object()) request.params = c["params"];
            request.sensitive = boolean(c, "sensitive");
            request.confirmed = boolean(c, "confirmed");
            request.expiresAt = str(c, "expires_at");
            reply.commands.push_back(std::move(request));
        }
    }

    if (body.contains("update") && body["update"].is_object()) {
        const auto& u = body["update"];
        UpdateOffer offer;
        offer.version = str(u, "version");
        offer.platform = str(u, "platform");
        offer.url = str(u, "url");
        offer.sha512 = str(u, "sha512");
        offer.signature = str(u, "signature");
        offer.install = boolean(u, "install");
        if (u.contains("size") && u["size"].is_number_unsigned()) offer.size = u["size"].get<std::uint64_t>();
        if (offer.version.empty() || offer.url.empty() || offer.sha512.size() != 128 || offer.signature.size() != 128 ||
            offer.size == 0) {
            reply.problems.push_back("an update offer with missing fields");
        } else {
            reply.update = std::move(offer);
        }
    }
    return reply;
}

json buildHeartbeat(const std::optional<monitor::Metrics>& metrics, const HeartbeatStatus& status) {
    json body = {
        {"version", std::string(version::kVersion)},
        {"platform", std::string(version::platform())},
        {"git_sha", std::string(version::kGitSha)},
        {"started_at", status.startedAt},
        {"uptime_seconds", status.uptimeSeconds},
        {"outbox", {{"pending", status.outboxPending}, {"dropped", status.outboxDropped}}},
    };
    if (metrics) body["metrics"] = metrics->toJson();
    if (status.inventoryHash) body["inventory_hash"] = *status.inventoryHash;
    return body;
}

}  // namespace hha::agent
