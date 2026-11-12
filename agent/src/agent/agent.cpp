#include "agent/agent.hpp"

#include "core/time.hpp"

#include <spdlog/spdlog.h>

namespace hha::agent {

Agent::Agent(Config config, Dependencies deps)
    : config_(std::move(config)),
      deps_(deps),
      interval_(config_.heartbeat),
      startedAt_(time::nowIso8601()),
      startedSteady_(SteadyClock::now()) {}

HeartbeatReply Agent::beat() {
    std::optional<monitor::Metrics> metrics;
    try {
        metrics = deps_.monitor.sample();
    } catch (const std::exception& e) {
        // A broken sensor must not stop the heartbeat: the server still learns the agent is alive
        spdlog::warn("Reading the computer failed: {}", e.what());
    }

    HeartbeatStatus status;
    status.startedAt = startedAt_;
    status.uptimeSeconds = std::chrono::duration_cast<std::chrono::seconds>(SteadyClock::now() - startedSteady_).count();

    const auto reply = HeartbeatReply::parse(deps_.api.post("heartbeat", buildHeartbeat(metrics, status)));
    for (const auto& problem : reply.problems) spdlog::warn("Heartbeat reply: {}", problem);
    if (reply.heartbeat != interval_) {
        spdlog::info("Heartbeat every {} s (from the server)", reply.heartbeat.count());
        interval_ = reply.heartbeat;
    }
    return reply;
}

void Agent::run(std::stop_token stop) {
    spdlog::info("Agent running; heartbeat every {} s", interval_.count());
    while (!stop.stop_requested()) {
        try {
            beat();
        } catch (const net::ApiError& e) {
            if (e.kind() == net::ApiError::Kind::Revoked) {
                spdlog::error("This computer was removed from the household on the dashboard. Stopping.");
                return;
            }
            spdlog::warn("Heartbeat failed: {}", e.what());
        } catch (const net::TransportError& e) {
            spdlog::warn("Server unreachable: {}", e.what());
        }
        if (!sleepFor(stop, interval_)) break;
    }
    spdlog::info("Agent stopped");
}

void Agent::wake() {
    {
        std::lock_guard lock(mutex_);
        woken_ = true;
    }
    wakeup_.notify_all();
}

bool Agent::sleepFor(std::stop_token& stop, std::chrono::milliseconds duration) {
    std::unique_lock lock(mutex_);
    wakeup_.wait_for(lock, stop, duration, [this] { return woken_; });
    woken_ = false;
    return !stop.stop_requested();
}

}  // namespace hha::agent
