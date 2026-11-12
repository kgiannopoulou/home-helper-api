#include "agent/agent.hpp"

#include "agent/inventory.hpp"
#include "core/time.hpp"

#include <spdlog/spdlog.h>

#include <thread>

namespace hha::agent {

namespace {
constexpr auto kAudioEvery = std::chrono::seconds(60);
}

Agent::Agent(Config config, Dependencies deps)
    : config_(std::move(config)),
      deps_(deps),
      interval_(config_.heartbeat),
      startedAt_(time::nowIso8601()),
      startedSteady_(SteadyClock::now()) {
    if (deps_.allowlist) {
        commandSummary_ = deps_.allowlist->summary();
        worker_ = std::make_unique<commands::CommandWorker>(
            *deps_.allowlist, deps_.runner ? deps_.runner : commands::ProcessRunner(commands::runProcess),
            [this](const commands::CommandResult& result) {
                addResult(result);
                wake();  // report it now, not at the next heartbeat
            });
    }
}

Agent::~Agent() {
    worker_.reset();
}

HeartbeatReply Agent::beat() {
    std::optional<monitor::Metrics> metrics;
    try {
        metrics = deps_.monitor.sample();
    } catch (const std::exception& e) {
        // A broken sensor must not stop the heartbeat: the server still learns the agent is alive
        spdlog::warn("Reading the computer failed: {}", e.what());
    }

    // The first beat after starting carries no hash, so the server asks for the inventory
    syncInventory(false);

    HeartbeatStatus status;
    status.startedAt = startedAt_;
    status.uptimeSeconds = std::chrono::duration_cast<std::chrono::seconds>(SteadyClock::now() - startedSteady_).count();
    status.inventoryHash = sentInventoryHash_;

    const auto reply = HeartbeatReply::parse(deps_.api.post("heartbeat", buildHeartbeat(metrics, status)));
    for (const auto& problem : reply.problems) spdlog::warn("Heartbeat reply: {}", problem);
    if (reply.heartbeat != interval_) {
        spdlog::info("Heartbeat every {} s (from the server)", reply.heartbeat.count());
        interval_ = reply.heartbeat;
    }
    if (reply.inventoryWanted) syncInventory(true);
    dispatch(reply.commands);
    sendResults();
    return reply;
}

void Agent::dispatch(const std::vector<CommandRequest>& requests) {
    for (const auto& request : requests) {
        if (!worker_) {
            commands::CommandResult r;
            r.id = request.id;
            r.key = request.key;
            r.status = "rejected";
            r.reason = "this computer has no commands configured";
            r.startedAt = time::nowIso8601();
            spdlog::warn("Command {} ({}) refused: no allowlist", request.id, request.key);
            addResult(std::move(r));
        } else if (!worker_->submit(request)) {
            spdlog::debug("Command {} already accepted", request.id);
        }
    }
}

void Agent::finishCommands(std::chrono::milliseconds limit) {
    const auto until = SteadyClock::now() + limit;
    while (commandsBusy() > 0 && SteadyClock::now() < until) {
        std::this_thread::sleep_for(std::chrono::milliseconds(50));
    }
    sendResults();
}

void Agent::addResult(commands::CommandResult result) {
    std::lock_guard lock(resultsMutex_);
    results_.push_back(std::move(result));
}

std::size_t Agent::pendingResults() const {
    std::lock_guard lock(resultsMutex_);
    return results_.size();
}

void Agent::sendResults() {
    while (true) {
        commands::CommandResult result;
        {
            std::lock_guard lock(resultsMutex_);
            if (results_.empty()) return;
            result = results_.front();
        }
        try {
            deps_.api.post("commands/" + result.id + "/result", result.toJson());
        } catch (const net::ApiError& e) {
            if (e.transient()) throw;
            // The server refused it for good (an unknown id, say): resending won't help
            spdlog::warn("The server refused the result of {}: {}", result.id, e.what());
        }
        std::lock_guard lock(resultsMutex_);
        results_.pop_front();
    }
}

void Agent::syncInventory(bool force) {
    const auto now = SteadyClock::now();
    const bool stale = !inventorySentAt_ || now - *inventorySentAt_ >= config_.inventoryEvery;
    const bool audioDue = deps_.audio && (!audioListedAt_ || now - *audioListedAt_ >= kAudioEvery);
    if (!force && !stale && !audioDue && sentInventoryHash_) return;

    monitor::SystemInfo system;
    try {
        system = deps_.monitor.info();
    } catch (const std::exception& e) {
        spdlog::warn("Reading the system information failed: {}", e.what());
    }
    const auto inventory = buildInventory(system, audioDevices(), commandSummary_);
    const auto hash = inventoryHash(inventory);
    if (!force && sentInventoryHash_ == hash) {
        inventorySentAt_ = now;  // unchanged: nothing to send
        return;
    }

    deps_.api.post("inventory", inventory);
    sentInventoryHash_ = hash;
    inventorySentAt_ = now;
    spdlog::info("Inventory sent: {} disk(s), {} audio device(s), {} command(s)", inventory["disks"].size(),
                 inventory["audio"].size(), inventory["commands"].size());
}

std::vector<audio::AudioDevice> Agent::audioDevices() {
    if (!deps_.audio) return {};
    const auto now = SteadyClock::now();
    if (audio_ && audioListedAt_ && now - *audioListedAt_ < kAudioEvery) return *audio_;

    std::vector<audio::AudioDevice> devices;
    try {
        devices = deps_.audio->list();
    } catch (const std::exception& e) {
        spdlog::warn("Listing audio devices failed: {}", e.what());
        return audio_.value_or(std::vector<audio::AudioDevice>{});
    }
    if (audio_) {
        const auto changes = audio::diff(*audio_, devices);
        for (const auto& d : changes.added) spdlog::info("Audio {} connected: {}", audio::toString(d.direction), d.name);
        for (const auto& d : changes.removed) spdlog::info("Audio {} removed: {}", audio::toString(d.direction), d.name);
    }
    audio_ = devices;
    audioListedAt_ = now;
    return devices;
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
