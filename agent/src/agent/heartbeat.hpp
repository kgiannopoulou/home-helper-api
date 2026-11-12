#pragma once

#include "monitor/metrics.hpp"

#include <nlohmann/json.hpp>

#include <chrono>
#include <cstdint>
#include <optional>
#include <string>
#include <vector>

/// The heartbeat, both ways (docs/protocol.md#post-heartbeat).
namespace hha::agent {

/// A command the server asks for. Nothing here is trusted: the allowlist
/// decides (src/commands/allowlist.hpp).
struct CommandRequest {
    std::string id;
    std::string key;
    nlohmann::json params = nlohmann::json::object();
    bool sensitive = false;
    bool confirmed = false;
    std::string expiresAt;
};

/// A newer release the server offers. Checked against the release key in
/// the agent's own config before anything is downloaded (src/update/).
struct UpdateOffer {
    std::string version;
    std::string platform;
    std::string url;
    std::string sha512;
    std::uint64_t size = 0;
    std::string signature;
    /// The owner pressed Install on the dashboard
    bool install = false;
};

struct HeartbeatReply {
    std::int64_t serverTime = 0;
    std::chrono::seconds heartbeat{30};
    bool inventoryWanted = false;
    std::vector<CommandRequest> commands;
    std::optional<UpdateOffer> update;
    /// Entries that did not parse: reported, never guessed at
    std::vector<std::string> problems;

    /// Lenient about extra fields, strict about the ones it uses. The interval
    /// is clamped to 5 s – 1 h whatever the server says.
    static HeartbeatReply parse(const nlohmann::json& body);
};

struct HeartbeatStatus {
    std::string startedAt;  // ISO 8601
    std::int64_t uptimeSeconds = 0;
    std::size_t outboxPending = 0;
    std::size_t outboxDropped = 0;
    std::optional<std::string> inventoryHash;
};

nlohmann::json buildHeartbeat(const std::optional<monitor::Metrics>& metrics, const HeartbeatStatus& status);

}  // namespace hha::agent
