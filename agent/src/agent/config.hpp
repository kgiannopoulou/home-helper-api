#pragma once

#include <nlohmann/json.hpp>

#include <chrono>
#include <filesystem>
#include <optional>
#include <stdexcept>
#include <string>
#include <vector>

namespace hha::agent {

/// The config file had mistakes; what() lists all of them, one per line.
class ConfigError : public std::runtime_error {
public:
    explicit ConfigError(const std::vector<std::string>& problems);
    const std::vector<std::string>& problems() const { return problems_; }

private:
    std::vector<std::string> problems_;
};

/// When a reading becomes an event (src/monitor/thresholds.hpp).
struct Thresholds {
    double cpuHighPercent = 90;
    double memoryHighPercent = 90;
    double diskLowFreePercent = 10;
    double temperatureHighCelsius = 85;
    /// Consecutive readings over the line before "high", and under it before "normal"
    int sustainSamples = 3;
};

/// agent.json (docs/configuration.md). Written by the person who installs the
/// agent; the server never sends any of it.
struct Config {
    std::filesystem::path file;

    /// Only used by `enroll`; afterwards the server URL comes from the credentials
    std::string serverUrl;
    /// Shown on the dashboard; the hostname when empty
    std::string name;
    bool allowInsecureHttp = false;
    std::optional<std::string> caFile;

    std::filesystem::path dataDir;
    std::string logLevel = "info";
    bool logToFile = true;

    std::chrono::seconds heartbeat{30};
    /// While the server is unreachable: 5 s, 10 s, 20 s… up to 2 min, with jitter (not in the file)
    std::chrono::milliseconds retryBase{5'000};
    std::chrono::milliseconds retryCap{120'000};
    std::chrono::seconds inventoryEvery{std::chrono::hours(6)};
    Thresholds thresholds;
    /// Only these mount points / drive letters; all fixed disks when empty
    std::vector<std::string> disks;
    bool audioDiscovery = true;
    std::size_t outboxMaxEvents = 1000;

    /// The allowlist, parsed by commands::Allowlist::fromJson
    nlohmann::json commands = nlohmann::json::array();

    /// Ed25519 public key (64 hex) that releases must be signed with; no updates without it
    std::optional<std::string> updatePublicKey;

    std::filesystem::path credentialsFile() const { return dataDir / "credentials.json"; }
    std::filesystem::path outboxFile() const { return dataDir / "outbox.jsonl"; }
    std::filesystem::path stateFile() const { return dataDir / "state.json"; }
    std::filesystem::path updatesDir() const { return dataDir / "updates"; }
    std::filesystem::path logFile() const { return dataDir / "logs" / "agent.log"; }

    /// Defaults for everything; a missing file is not an error (`enroll` works without one).
    static Config load(const std::filesystem::path& file);
    /// Throws ConfigError listing every problem, not just the first.
    static Config fromJson(const nlohmann::json& json, const std::filesystem::path& file = {});

    /// A starting config for `home-helper-agent init`, with a commented-by-example allowlist.
    static nlohmann::json example(const std::string& serverUrl);
};

}  // namespace hha::agent
