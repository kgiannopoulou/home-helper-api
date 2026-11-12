#pragma once

#include "agent/heartbeat.hpp"
#include "commands/allowlist.hpp"
#include "commands/process.hpp"

#include <nlohmann/json.hpp>

#include <condition_variable>
#include <deque>
#include <functional>
#include <mutex>
#include <set>
#include <stop_token>
#include <string>
#include <thread>

namespace hha::commands {

/// What came of one request (docs/protocol.md#post-commandsidresult).
struct CommandResult {
    std::string id;
    std::string key;
    std::string status;  // succeeded, failed, timed_out, rejected
    int exitCode = -1;
    std::string output;
    bool truncated = false;
    long long durationMs = 0;
    std::string startedAt;
    std::string reason;  // for rejected

    nlohmann::json toJson() const;
    static CommandResult fromJson(const nlohmann::json& j);
};

/// Checks one request against the allowlist and, if it passes, runs it.
/// Every decision is logged, accepted or not: the log is the audit trail.
CommandResult execute(const Allowlist& allowlist, const agent::CommandRequest& request, const ProcessRunner& runner);

/// Runs requests one at a time on its own thread, so a 20-second ping never
/// holds up the heartbeat. Each id runs at most once per agent run, even if
/// the server sends it again before it has the result.
class CommandWorker {
public:
    using ResultHandler = std::function<void(const CommandResult&)>;

    CommandWorker(const Allowlist& allowlist, ProcessRunner runner, ResultHandler onResult);
    ~CommandWorker();

    CommandWorker(const CommandWorker&) = delete;
    CommandWorker& operator=(const CommandWorker&) = delete;

    /// False when this id was already accepted (a duplicate from the server)
    bool submit(const agent::CommandRequest& request);

    /// Requests waiting or running (for tests and the status line)
    std::size_t busy() const;

private:
    void loop(std::stop_token stop);

    const Allowlist& allowlist_;
    ProcessRunner runner_;
    ResultHandler onResult_;

    mutable std::mutex mutex_;
    std::condition_variable_any ready_;
    std::deque<agent::CommandRequest> queue_;
    std::set<std::string> seen_;
    std::size_t running_ = 0;
    std::jthread thread_;
};

}  // namespace hha::commands
