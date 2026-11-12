#include "commands/executor.hpp"

#include "core/time.hpp"

#include <spdlog/spdlog.h>

namespace hha::commands {

using nlohmann::json;

json CommandResult::toJson() const {
    json j = {{"status", status},   {"exit_code", exitCode},     {"output", output},
              {"truncated", truncated}, {"duration_ms", durationMs}, {"started_at", startedAt}};
    j["reason"] = reason.empty() ? json(nullptr) : json(reason);
    if (status == "rejected") j["exit_code"] = nullptr;
    return j;
}

CommandResult CommandResult::fromJson(const json& j) {
    CommandResult r;
    r.id = j.value("id", "");
    r.key = j.value("key", "");
    r.status = j.value("status", "");
    r.exitCode = j.contains("exit_code") && j["exit_code"].is_number_integer() ? j["exit_code"].get<int>() : -1;
    r.output = j.value("output", "");
    r.truncated = j.value("truncated", false);
    r.durationMs = j.value("duration_ms", 0LL);
    r.startedAt = j.value("started_at", "");
    r.reason = j.contains("reason") && j["reason"].is_string() ? j["reason"].get<std::string>() : "";
    return r;
}

CommandResult execute(const Allowlist& allowlist, const agent::CommandRequest& request, const ProcessRunner& runner) {
    CommandResult result;
    result.id = request.id;
    result.key = request.key;
    result.startedAt = time::nowIso8601();

    const auto decision = allowlist.resolve(request, std::chrono::system_clock::now());
    if (const auto* rejection = std::get_if<Rejection>(&decision)) {
        result.status = "rejected";
        result.reason = rejection->reason;
        spdlog::warn("Command {} ({}) refused: {}", request.id, request.key, rejection->reason);
        return result;
    }

    const auto& resolved = std::get<Resolved>(decision);
    spdlog::info("Command {} ({}) starting: {}{}", request.id, request.key, resolved.spec->program.string(),
                 resolved.spec->sensitive ? " [sensitive, confirmed by the owner]" : "");
    try {
        const auto p = runner(resolved.spec->program, resolved.argv, resolved.spec->timeout, resolved.spec->maxOutputBytes);
        result.exitCode = p.exitCode;
        result.output = p.output;
        result.truncated = p.truncated;
        result.durationMs = p.duration.count();
        result.status = p.timedOut ? "timed_out" : (p.exitCode == 0 ? "succeeded" : "failed");
    } catch (const std::exception& e) {
        result.status = "failed";
        result.reason = e.what();
    }
    spdlog::info("Command {} ({}) {}: exit {} in {} ms", request.id, request.key, result.status, result.exitCode,
                 result.durationMs);
    return result;
}

CommandWorker::CommandWorker(const Allowlist& allowlist, ProcessRunner runner, ResultHandler onResult)
    : allowlist_(allowlist),
      runner_(std::move(runner)),
      onResult_(std::move(onResult)),
      thread_([this](std::stop_token stop) { loop(stop); }) {}

CommandWorker::~CommandWorker() {
    thread_.request_stop();
    ready_.notify_all();
}

bool CommandWorker::submit(const agent::CommandRequest& request) {
    {
        std::lock_guard lock(mutex_);
        if (!seen_.insert(request.id).second) return false;
        queue_.push_back(request);
    }
    ready_.notify_one();
    return true;
}

std::size_t CommandWorker::busy() const {
    std::lock_guard lock(mutex_);
    return queue_.size() + running_;
}

void CommandWorker::loop(std::stop_token stop) {
    while (true) {
        agent::CommandRequest request;
        {
            std::unique_lock lock(mutex_);
            if (!ready_.wait(lock, stop, [this] { return !queue_.empty(); })) return;
            request = std::move(queue_.front());
            queue_.pop_front();
            ++running_;
        }
        const auto result = execute(allowlist_, request, runner_);
        onResult_(result);
        std::lock_guard lock(mutex_);
        --running_;
    }
}

}  // namespace hha::commands
