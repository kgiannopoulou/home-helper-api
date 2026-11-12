#pragma once

#include "agent/config.hpp"
#include "agent/heartbeat.hpp"
#include "monitor/metrics.hpp"
#include "net/api_client.hpp"

#include <chrono>
#include <condition_variable>
#include <functional>
#include <mutex>
#include <stop_token>

namespace hha::agent {

/// The agent's loop (docs/design.md#shape): every heartbeat interval, read the
/// computer, send the heartbeat, act on the reply. Everything it talks to is
/// passed in, so the tests drive it with fakes and a fake clock.
class Agent {
public:
    using SteadyClock = std::chrono::steady_clock;

    struct Dependencies {
        net::ApiClient& api;
        monitor::SystemMonitor& monitor;
    };

    Agent(Config config, Dependencies deps);

    /// One heartbeat: sample, send, handle the reply. Network and server
    /// errors propagate (run() decides what to do about them).
    HeartbeatReply beat();

    /// Until stop is requested. Returns early only when the computer was
    /// removed from the household (ApiError Revoked).
    void run(std::stop_token stop);

    /// Wakes run() for an immediate heartbeat (a new event, a local command finished).
    void wake();

    std::chrono::seconds interval() const { return interval_; }

private:
    /// Sleeps up to `duration`; false when stop was requested.
    bool sleepFor(std::stop_token& stop, std::chrono::milliseconds duration);

    Config config_;
    Dependencies deps_;
    std::chrono::seconds interval_;
    std::string startedAt_;
    SteadyClock::time_point startedSteady_;

    std::mutex mutex_;
    std::condition_variable_any wakeup_;
    bool woken_ = false;
};

}  // namespace hha::agent
