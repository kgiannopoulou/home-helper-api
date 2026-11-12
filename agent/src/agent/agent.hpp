#pragma once

#include "agent/backoff.hpp"
#include "agent/config.hpp"
#include "agent/heartbeat.hpp"
#include "audio/audio_devices.hpp"
#include "commands/executor.hpp"
#include "events/outbox.hpp"
#include "events/thresholds.hpp"
#include "monitor/metrics.hpp"
#include "net/api_client.hpp"
#include "update/updater.hpp"

#include <chrono>
#include <condition_variable>
#include <functional>
#include <memory>
#include <mutex>
#include <optional>
#include <stop_token>

namespace hha::agent {

/// The agent's loop (docs/design.md#shape): every heartbeat interval, read the
/// computer, send the heartbeat, act on the reply, and send what is waiting
/// in the outbox. Everything it talks to is passed in, so the tests drive it
/// with fakes.
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
        /// Events and results waiting to be sent; an in-memory one when null
        events::Outbox* outbox = nullptr;
        /// Signed updates; offers are ignored when null
        update::Updater* updater = nullptr;
    };

    /// Why run() returned
    enum class StopReason { Requested, Revoked, RestartForUpdate };

    Agent(Config config, Dependencies deps);
    ~Agent();

    Agent(const Agent&) = delete;
    Agent& operator=(const Agent&) = delete;

    /// One heartbeat: sample, send, handle the reply, flush the outbox.
    /// Network and server errors propagate (run() decides what to do).
    HeartbeatReply beat();

    /// Until stop is requested, the computer is removed from the household
    /// (ApiError Revoked), or a verified update is staged and needs a restart.
    StopReason run(std::stop_token stop);

    /// Wakes run() for an immediate heartbeat (a new event, a command finished).
    void wake();

    /// Records an event and wakes the loop to send it
    void emit(const events::Event& event);

    /// Waits (up to `limit`) for queued and running commands, then sends their
    /// results. For `run --once` and a clean shutdown.
    void finishCommands(std::chrono::milliseconds limit);

    std::chrono::seconds interval() const { return interval_; }
    /// False from the first failed attempt until the next success
    bool online() const { return !offlineSince_.has_value(); }
    const std::optional<std::string>& sentInventoryHash() const { return sentInventoryHash_; }
    /// Results waiting to be sent (finished commands whose result the server doesn't have yet)
    std::size_t pendingResults() const;
    /// Commands queued or running on this computer
    std::size_t commandsBusy() const { return worker_ ? worker_->busy() : 0; }
    events::Outbox& outbox() { return *outbox_; }

private:
    /// Sends the inventory when it changed, the server asked, or `force`
    void syncInventory(bool force);
    /// Audio devices, re-listed at most once a minute; plugging and unplugging become events
    std::vector<audio::AudioDevice> audioDevices();
    bool sleepFor(std::stop_token& stop, std::chrono::milliseconds duration);
    /// Hands the reply's commands to the worker (or rejects them all without an allowlist)
    void dispatch(const std::vector<CommandRequest>& requests);
    void addResult(const commands::CommandResult& result);
    /// Posts finished results, oldest first; a network error keeps the rest
    void sendResults();
    /// Posts waiting events in batches of up to 100
    void sendEvents();
    /// The server didn't answer (or answered 429/5xx): how long until the next try
    std::chrono::milliseconds wentOffline(const std::string& why);
    /// A heartbeat got through: if it had been offline, say so with an event
    void backOnline();

    Config config_;
    Dependencies deps_;
    std::chrono::seconds interval_;
    std::string startedAt_;
    SteadyClock::time_point startedSteady_;

    std::unique_ptr<events::Outbox> ownedOutbox_;
    events::Outbox* outbox_;
    events::ThresholdWatcher thresholds_;

    Backoff backoff_;
    std::optional<SteadyClock::time_point> offlineSince_;
    std::string offlineReason_;

    nlohmann::json commandSummary_ = nlohmann::json::array();
    std::optional<std::string> sentInventoryHash_;
    std::optional<SteadyClock::time_point> inventorySentAt_;
    std::optional<std::vector<audio::AudioDevice>> audio_;
    std::optional<SteadyClock::time_point> audioListedAt_;

    std::mutex mutex_;
    std::condition_variable_any wakeup_;
    bool woken_ = false;
    bool restartForUpdate_ = false;

    /// Last: its thread calls back into the members above, so it stops first
    std::unique_ptr<commands::CommandWorker> worker_;
};

}  // namespace hha::agent
