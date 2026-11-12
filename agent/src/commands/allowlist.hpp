#pragma once

#include "agent/heartbeat.hpp"

#include <nlohmann/json.hpp>

#include <chrono>
#include <filesystem>
#include <map>
#include <optional>
#include <regex>
#include <variant>
#include <string>
#include <vector>

/// The only things Home Helper can run on this computer (docs/commands.md).
///
/// Each entry is written by the person who installed the agent, in agent.json:
///
///   { "key": "ping_router", "description": "Ping a device on the home network",
///     "program": "C:\\Windows\\System32\\PING.EXE", "args": ["-n", "4", "{host}"],
///     "params": { "host": { "pattern": "^192\\.168\\.[0-9]{1,3}\\.[0-9]{1,3}$" } },
///     "sensitive": false, "timeout_seconds": 20 }
///
/// The rules that make it safe:
///   - the program is an absolute path to an existing file, and not a shell or a
///     script interpreter (they would turn a parameter into code)
///   - it is started directly, never through a shell, with exactly these arguments
///   - a parameter fills one whole argument ("{host}"), never part of one, so it
///     can't add arguments; it must match its pattern in full, may not start with
///     "-" (an option), and may not contain control characters
///   - sensitive commands run only when the household owner confirmed them
namespace hha::commands {

struct ParamSpec {
    std::string name;
    std::string pattern;  // as written, for the inventory
    std::regex regex;     // ECMAScript, matched against the whole value
    std::string description;
};

struct CommandSpec {
    std::string key;
    std::string description;
    std::filesystem::path program;
    std::vector<std::string> args;  // literals, or "{param}"
    std::vector<ParamSpec> params;
    bool sensitive = false;
    std::chrono::seconds timeout{30};
    std::size_t maxOutputBytes = 16 * 1024;
};

/// A request that passed every check: what exactly to start.
struct Resolved {
    const CommandSpec* spec = nullptr;
    std::vector<std::string> argv;  // without the program
};

/// Why a request was refused. Reported back to the server as status "rejected".
struct Rejection {
    std::string reason;
};

class Allowlist {
public:
    Allowlist() = default;

    /// The "commands" array of agent.json. Throws agent::ConfigError listing every problem.
    /// `checkPrograms` = false skips the "program exists" check (for tests of the other rules).
    static Allowlist fromJson(const nlohmann::json& commands, bool checkPrograms = true);

    /// Checks a request from the server against this computer's list.
    /// `now` decides expiry (the server's expires_at).
    std::variant<Resolved, Rejection> resolve(const agent::CommandRequest& request,
                                              std::chrono::system_clock::time_point now) const;

    const CommandSpec* find(const std::string& key) const;
    const std::vector<CommandSpec>& entries() const { return entries_; }
    bool empty() const { return entries_.empty(); }

    /// For the inventory: key, description, sensitive and parameters, never the program.
    nlohmann::json summary() const;

private:
    std::vector<CommandSpec> entries_;
};

/// True for shells and script hosts: cmd, powershell, pwsh, bash, sh, python, node, wscript…
bool isInterpreter(const std::filesystem::path& program);

/// The checks every parameter value must pass, before its own pattern.
std::optional<std::string> checkValue(const std::string& value);

}  // namespace hha::commands
