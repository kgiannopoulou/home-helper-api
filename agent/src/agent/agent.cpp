#include "agent/agent.hpp"

#include "agent/inventory.hpp"
#include "core/time.hpp"
#include "core/version.hpp"

#include <spdlog/spdlog.h>

#include <algorithm>
#include <thread>

namespace hha::agent {

using events::Event;
using events::Severity;
using nlohmann::json;

namespace {
constexpr auto kAudioEvery = std::chrono::seconds(60);
constexpr std::size_t kEventBatch = 100;
}  // namespace

Agent::Agent(Config config, Dependencies deps)
    : config_(std::move(config)),
      deps_(deps),
      interval_(config_.heartbeat),
      startedAt_(time::nowIso8601()),
      startedSteady_(SteadyClock::now()),
      thresholds_(config_.thresholds),
      backoff_(config_.retryBase, config_.retryCap) {
    if (deps_.outbox) {
        outbox_ = deps_.outbox;
    } else {
        ownedOutbox_ = std::make_unique<events::Outbox>(std::filesystem::path{}, config_.outboxMaxEvents);
        outbox_ = ownedOutbox_.get();
    }
    if (deps_.allowlist) {
        commandSummary_ = deps_.allowlist->summary();
        worker_ = std::make_unique<commands::CommandWorker>(
            *deps_.allowlist, deps_.runner ? deps_.runner : commands::ProcessRunner(commands::runProcess),
            [this](const commands::CommandResult& result) {
                addResult(result);
                wake();  // report it now, not at the next heartbeat
            });
    }
    outbox_->add(Event::make("agent.started", Severity::Info,
                             "Agent " + std::string(version::kVersion) + " started",
                             {{"version", std::string(version::kVersion)}, {"platform", std::string(version::platform())}}));
}

Agent::~Agent() {
    worker_.reset();
}

void Agent::emit(const Event& event) {
    spdlog::info("Event {}: {}", event.type, event.message);
    outbox_->add(event);
    wake();
}

HeartbeatReply Agent::beat() {
    std::optional<monitor::Metrics> metrics;
    try {
        metrics = deps_.monitor.sample();
        for (const auto& e : thresholds_.feed(*metrics)) emit(e);
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
    status.outboxPending = outbox_->size();
    status.outboxDropped = outbox_->dropped();

    const auto reply = HeartbeatReply::parse(deps_.api.post("heartbeat", buildHeartbeat(metrics, status)));
    for (const auto& problem : reply.problems) spdlog::warn("Heartbeat reply: {}", problem);
    if (reply.heartbeat != interval_) {
        spdlog::info("Heartbeat every {} s (from the server)", reply.heartbeat.count());
        interval_ = reply.heartbeat;
    }
    if (reply.inventoryWanted) syncInventory(true);
    dispatch(reply.commands);
    sendResults();
    sendEvents();
    return reply;
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
        for (const auto& d : changes.added) {
            const auto what = d.direction == audio::Direction::Input ? "Microphone" : "Speaker";
            emit(Event::make("audio.added", Severity::Info, std::string(what) + " connected: " + d.name, d.toJson()));
        }
        for (const auto& d : changes.removed) {
            const auto what = d.direction == audio::Direction::Input ? "Microphone" : "Speaker";
            emit(Event::make("audio.removed", Severity::Info, std::string(what) + " removed: " + d.name, d.toJson()));
        }
    }
    audio_ = devices;
    audioListedAt_ = now;
    return devices;
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
            addResult(r);
        } else if (!worker_->submit(request)) {
            spdlog::debug("Command {} already accepted", request.id);
        }
    }
}

void Agent::addResult(const commands::CommandResult& result) {
    auto body = result.toJson();
    body["id"] = result.id;
    body["key"] = result.key;
    outbox_->addResult(body);
    if (result.status == "rejected") {
        // Somebody asked for something this computer doesn't allow: worth seeing on the dashboard
        outbox_->add(Event::make("command.rejected", Severity::Warning,
                                 "Refused \"" + result.key + "\": " + result.reason,
                                 {{"command_id", result.id}, {"key", result.key}, {"reason", result.reason}}));
    }
}

std::size_t Agent::pendingResults() const {
    return outbox_->peek("result", 1'000'000).size();
}

void Agent::finishCommands(std::chrono::milliseconds limit) {
    const auto until = SteadyClock::now() + limit;
    while (commandsBusy() > 0 && SteadyClock::now() < until) {
        std::this_thread::sleep_for(std::chrono::milliseconds(50));
    }
    sendResults();
    sendEvents();
}

void Agent::sendResults() {
    for (const auto& entry : outbox_->peek("result", 50)) {
        const auto id = entry.body.value("id", "");
        auto body = entry.body;
        body.erase("id");
        body.erase("key");
        try {
            deps_.api.post("commands/" + id + "/result", body);
        } catch (const net::ApiError& e) {
            if (e.transient() || e.kind() == net::ApiError::Kind::Unauthorized ||
                e.kind() == net::ApiError::Kind::Revoked) {
                throw;
            }
            // Refused for good (validation): resending won't help
            spdlog::warn("The server refused the result of {}: {}", id, e.what());
        }
        outbox_->remove("result", {id});
    }
}

void Agent::sendEvents() {
    while (true) {
        const auto batch = outbox_->peek("event", kEventBatch);
        if (batch.empty()) return;
        json events = json::array();
        std::vector<std::string> ids;
        for (const auto& e : batch) {
            events.push_back(e.body);
            ids.push_back(e.body.value("id", ""));
        }
        try {
            deps_.api.post("events", {{"events", events}});
        } catch (const net::ApiError& e) {
            if (e.transient() || e.kind() == net::ApiError::Kind::Unauthorized ||
                e.kind() == net::ApiError::Kind::Revoked) {
                throw;
            }
            spdlog::warn("The server refused {} event(s): {}", ids.size(), e.what());
        }
        outbox_->remove("event", ids);
        if (batch.size() < kEventBatch) return;
    }
}

std::chrono::milliseconds Agent::wentOffline(const std::string& why) {
    if (!offlineSince_) {
        offlineSince_ = SteadyClock::now();
        offlineReason_ = why;
        // Once, not on every retry: a night without Wi-Fi shouldn't fill the log
        spdlog::warn("Server unreachable ({}); retrying with backoff. Events and results are kept until it's back.", why);
    } else {
        spdlog::debug("Still unreachable ({}), attempt {}", why, backoff_.attempts() + 1);
    }
    return backoff_.next();
}

void Agent::backOnline() {
    if (!offlineSince_) return;
    const auto offline = std::chrono::duration_cast<std::chrono::seconds>(SteadyClock::now() - *offlineSince_);
    const auto attempts = backoff_.attempts();
    spdlog::info("Back online after {} ({} attempt(s))", time::humanDuration(offline), attempts);
    emit(Event::make("agent.reconnected", Severity::Info, "Back online after " + time::humanDuration(offline),
                     {{"offline_seconds", offline.count()}, {"attempts", attempts}, {"reason", offlineReason_}}));
    offlineSince_.reset();
    offlineReason_.clear();
    backoff_.reset();
}

void Agent::run(std::stop_token stop) {
    spdlog::info("Agent running; heartbeat every {} s", interval_.count());
    while (!stop.stop_requested()) {
        std::chrono::milliseconds wait = interval_;
        try {
            beat();
            backOnline();
        } catch (const net::ApiError& e) {
            switch (e.kind()) {
                case net::ApiError::Kind::Revoked:
                    spdlog::error("This computer was removed from the household on the dashboard. Stopping.");
                    return;
                case net::ApiError::Kind::RateLimited:
                case net::ApiError::Kind::Server:
                    wait = wentOffline(e.what());
                    break;
                case net::ApiError::Kind::Unauthorized:
                    // Wrong secret, unknown agent: retrying fast won't fix it; someone has to look
                    spdlog::error("The server rejects this computer's credentials ({}). Re-enroll it if this lasts: "
                                  "home-helper-agent enroll --force --code …",
                                  e.code());
                    wait = std::max<std::chrono::milliseconds>(config_.retryCap, interval_);
                    break;
                default:
                    // The server answered but refused something: not offline; try again at the usual pace
                    spdlog::warn("Heartbeat refused: {}", e.what());
                    break;
            }
        } catch (const net::TransportError& e) {
            wait = wentOffline(e.what());
        } catch (const std::exception& e) {
            // A bug or a surprise from the server: log it and keep the agent alive
            spdlog::error("Heartbeat failed unexpectedly: {}", e.what());
        }
        if (!sleepFor(stop, wait)) break;
    }

    // Say goodbye if the server is there; the outbox keeps it otherwise
    outbox_->add(Event::make("agent.stopping", Severity::Info, "Agent stopping"));
    try {
        finishCommands(std::chrono::seconds(10));
    } catch (const std::exception& e) {
        spdlog::info("Not sent before stopping (kept for next time): {}", e.what());
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
