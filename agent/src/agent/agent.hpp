#pragma once

#include "agent/config.hpp"
#include "agent/heartbeat.hpp"
#include "audio/audio_devices.hpp"
#include "commands/executor.hpp"
#include "monitor/metrics.hpp"
#include "net/api_client.hpp"

#include <chrono>
#include <condition_variable>
#include <deque>
#include <functional>
#include <mutex>
#include <optional>
#include <stop_token>

namespace hha::agent {

/// The agent's loop (docs/design.md#shape): every heartbeat interval, read the
/// computer, send the heartbeat, act on the reply. Everything it talks to is
/// passed in, so the tests drive it with fakes.
class Agent {
public:
    using SteadyClock = std::chrono::steady_clock;

    struct Dependencies {
        net::ApiClient& api;
        monitor::SystemMonitor& monitor;
        /// Null when audio discovery is off in the config
        audio::AudioDiscovery* audio = nullptr;
        /// This computer's allowlist; no commands run without one
        const commands::Allowlist* allowlist = nullptr;
        /// How programs are started (tests replace it); commands::runProcess by default
        commands::ProcessRunner runner = {};
    };

    Agent(Config config, Dependencies deps);
    ~Agent();

    Agent(const Agent&) = delete;
    Agent& operator=(const Agent&) = delete;

    /// One heartbeat: sample, send, handle the reply. Network and server
    /// errors propagate (run() decides what to do about them).
    HeartbeatReply beat();

    /// Until stop is requested. Returns early only when the computer was
    /// removed from the household (ApiError Revoked).
    void run(std::stop_token stop);

    /// Wakes run() for an immediate heartbeat (a new event, a local command finished).
    void wake();

    std::chrono::seconds interval() const { return interval_; }
    const std::optional<std::string>& sentInventoryHash() const { return sentInventoryHash_; }

    /// Waits (up to `limit`) for queued and running commands, then sends their
    /// results. For `run --once` and a clean shutdown.
    void finishCommands(std::chrono::milliseconds limit);

    /// Results waiting to be sent (finished commands whose result the server doesn't have yet)
    std::size_t pendingResults() const;
    /// Commands queued or running on this computer
    std::size_t commandsBusy() const { return worker_ ? worker_->busy() : 0; }

private:
    /// Sends the inventory when it changed, the server asked, or `force`
    void syncInventory(bool force);
    /// Audio devices, re-listed at most once a minute; logs what appeared or went
    std::vector<audio::AudioDevice> audioDevices();
    bool sleepFor(std::stop_token& stop, std::chrono::milliseconds duration);
    /// Hands the reply's commands to the worker (or rejects them all without an allowlist)
    void dispatch(const std::vector<CommandRequest>& requests);
    /// Posts finished results; a network error keeps the rest for the next beat
    void sendResults();
    void addResult(commands::CommandResult result);

    Config config_;
    Dependencies deps_;
    std::chrono::seconds interval_;
    std::string startedAt_;
    SteadyClock::time_point startedSteady_;

    nlohmann::json commandSummary_ = nlohmann::json::array();
    std::optional<std::string> sentInventoryHash_;
    std::optional<SteadyClock::time_point> inventorySentAt_;
    std::optional<std::vector<audio::AudioDevice>> audio_;
    std::optional<SteadyClock::time_point> audioListedAt_;

    std::mutex mutex_;
    std::condition_variable_any wakeup_;
    bool woken_ = false;

    mutable std::mutex resultsMutex_;
    std::deque<commands::CommandResult> results_;
    /// Last: its thread calls back into the members above, so it stops first
    std::unique_ptr<commands::CommandWorker> worker_;
};

}  // namespace hha::agent
