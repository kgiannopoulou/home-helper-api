#include "agent/config.hpp"

#include "core/logging.hpp"
#include "core/paths.hpp"

#include <set>

namespace hha::agent {

using nlohmann::json;

namespace {

std::string joinLines(const std::vector<std::string>& problems) {
    std::string out = "the config has " + std::to_string(problems.size()) + " problem(s):";
    for (const auto& p : problems) out += "\n  - " + p;
    return out;
}

/// Reads typed values and collects problems instead of throwing at the first one.
class Reader {
public:
    Reader(const json& root, std::vector<std::string>& problems) : root_(root), problems_(problems) {}

    template <typename T>
    void read(const json& obj, const std::string& path, const char* key, T& out) {
        if (!obj.is_object() || !obj.contains(key) || obj[key].is_null()) return;
        try {
            out = obj[key].get<T>();
        } catch (const json::exception&) {
            problems_.push_back(path + key + ": wrong type");
        }
    }

    void number(const json& obj, const std::string& path, const char* key, double& out, double min, double max) {
        read(obj, path, key, out);
        if (out < min || out > max) {
            problems_.push_back(path + key + ": must be between " + std::to_string(min) + " and " + std::to_string(max));
        }
    }

    const json& root() const { return root_; }

private:
    const json& root_;
    std::vector<std::string>& problems_;
};

}  // namespace

ConfigError::ConfigError(const std::vector<std::string>& problems)
    : std::runtime_error(joinLines(problems)), problems_(problems) {}

Config Config::load(const std::filesystem::path& file) {
    if (!std::filesystem::exists(file)) {
        return fromJson(json::object(), file);
    }
    const auto parsed = json::parse(paths::readFile(file), nullptr, false, true);  // comments allowed
    if (parsed.is_discarded() || !parsed.is_object()) {
        throw ConfigError({file.string() + " is not a JSON object"});
    }
    return fromJson(parsed, file);
}

Config Config::fromJson(const json& j, const std::filesystem::path& file) {
    std::vector<std::string> problems;
    Reader r(j, problems);
    Config c;
    c.file = file;

    static const std::set<std::string> known = {"server_url", "name", "allow_insecure_http", "ca_file", "data_dir",
                                                "log", "heartbeat_seconds", "inventory_hours", "monitor", "audio",
                                                "outbox", "commands", "updates", "$schema", "_comment"};
    for (const auto& [key, _] : j.items()) {
        if (!known.contains(key)) problems.push_back(key + ": unknown setting (a typo?)");
    }

    r.read(j, "", "server_url", c.serverUrl);
    r.read(j, "", "name", c.name);
    r.read(j, "", "allow_insecure_http", c.allowInsecureHttp);
    std::string ca;
    r.read(j, "", "ca_file", ca);
    if (!ca.empty()) c.caFile = ca;

    std::string dataDir;
    r.read(j, "", "data_dir", dataDir);
    c.dataDir = dataDir.empty() ? paths::defaultDataDir() : std::filesystem::path(dataDir);

    if (j.contains("log")) {
        const auto& log = j["log"];
        r.read(log, "log.", "level", c.logLevel);
        r.read(log, "log.", "file", c.logToFile);
        if (!logging::isLevel(c.logLevel)) {
            problems.push_back("log.level: one of trace, debug, info, warn, error, critical, off");
        }
    }

    double heartbeat = 30;
    r.number(j, "", "heartbeat_seconds", heartbeat, 5, 3600);
    c.heartbeat = std::chrono::seconds(static_cast<long long>(heartbeat));
    double inventoryHours = 6;
    r.number(j, "", "inventory_hours", inventoryHours, 1, 168);
    c.inventoryEvery = std::chrono::seconds(static_cast<long long>(inventoryHours * 3600));

    if (j.contains("monitor")) {
        const auto& m = j["monitor"];
        auto& t = c.thresholds;
        r.number(m, "monitor.", "cpu_high_percent", t.cpuHighPercent, 1, 100);
        r.number(m, "monitor.", "memory_high_percent", t.memoryHighPercent, 1, 100);
        r.number(m, "monitor.", "disk_low_free_percent", t.diskLowFreePercent, 0, 99);
        r.number(m, "monitor.", "temperature_high_c", t.temperatureHighCelsius, 30, 120);
        double sustain = t.sustainSamples;
        r.number(m, "monitor.", "sustain_samples", sustain, 1, 100);
        t.sustainSamples = static_cast<int>(sustain);
        r.read(m, "monitor.", "disks", c.disks);
    }
    if (j.contains("audio")) r.read(j["audio"], "audio.", "enabled", c.audioDiscovery);
    if (j.contains("outbox")) {
        double max = static_cast<double>(c.outboxMaxEvents);
        r.number(j["outbox"], "outbox.", "max_events", max, 10, 100000);
        c.outboxMaxEvents = static_cast<std::size_t>(max);
    }

    if (j.contains("commands")) {
        if (j["commands"].is_array()) {
            c.commands = j["commands"];  // checked in detail by commands::Allowlist
        } else {
            problems.push_back("commands: must be a list");
        }
    }

    if (j.contains("updates")) {
        std::string key;
        r.read(j["updates"], "updates.", "public_key", key);
        if (!key.empty()) {
            if (key.size() != 64 || key.find_first_not_of("0123456789abcdefABCDEF") != std::string::npos) {
                problems.push_back("updates.public_key: 64 hex characters (an Ed25519 public key)");
            } else {
                c.updatePublicKey = key;
            }
        }
    }

    if (!problems.empty()) throw ConfigError(problems);
    return c;
}

json Config::example(const std::string& serverUrl) {
    json commands = json::array();
#ifdef _WIN32
    commands.push_back({{"key", "ping_router"},
                        {"description", "Ping a device on the home network four times"},
                        {"program", "C:\\Windows\\System32\\PING.EXE"},
                        {"args", {"-n", "4", "{host}"}},
                        {"params", {{"host", {{"pattern", "^192\\.168\\.[0-9]{1,3}\\.[0-9]{1,3}$"},
                                              {"description", "An address on the home network, 192.168.x.x"}}}}},
                        {"timeout_seconds", 20}});
    commands.push_back({{"key", "flush_dns"},
                        {"description", "Clear the DNS cache"},
                        {"program", "C:\\Windows\\System32\\ipconfig.exe"},
                        {"args", {"/flushdns"}}});
    commands.push_back({{"key", "restart_computer"},
                        {"description", "Restart this computer in one minute"},
                        {"program", "C:\\Windows\\System32\\shutdown.exe"},
                        {"args", {"/r", "/t", "60", "/c", "Restarting for Home Helper"}},
                        {"sensitive", true}});
#else
    commands.push_back({{"key", "ping_router"},
                        {"description", "Ping a device on the home network four times"},
                        {"program", "/usr/bin/ping"},
                        {"args", {"-c", "4", "{host}"}},
                        {"params", {{"host", {{"pattern", "^192\\.168\\.[0-9]{1,3}\\.[0-9]{1,3}$"},
                                              {"description", "An address on the home network, 192.168.x.x"}}}}},
                        {"timeout_seconds", 20}});
    commands.push_back({{"key", "disk_usage"},
                        {"description", "Show how full each disk is"},
                        {"program", "/usr/bin/df"},
                        {"args", {"-h", "-x", "tmpfs"}}});
    commands.push_back({{"key", "restart_media_server"},
                        {"description", "Restart the Jellyfin media server"},
                        {"program", "/usr/bin/systemctl"},
                        {"args", {"restart", "jellyfin.service"}},
                        {"sensitive", true}});
#endif
    return {
        {"server_url", serverUrl},
        {"name", ""},
        {"log", {{"level", "info"}, {"file", true}}},
        {"heartbeat_seconds", 30},
        {"monitor",
         {{"cpu_high_percent", 90},
          {"memory_high_percent", 90},
          {"disk_low_free_percent", 10},
          {"temperature_high_c", 85},
          {"sustain_samples", 3}}},
        {"audio", {{"enabled", true}}},
        {"commands", commands},
        {"updates", {{"public_key", ""}}},
    };
}

}  // namespace hha::agent
