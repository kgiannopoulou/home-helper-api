#pragma once

#include <nlohmann/json.hpp>

#include <filesystem>
#include <mutex>
#include <optional>
#include <string>
#include <vector>

namespace hha::events {

enum class Severity { Info, Warning, Critical };

std::string_view toString(Severity severity);

/// Something that happened on this computer (docs/protocol.md#post-events).
struct Event {
    std::string id;  // uuid4, so a resend after a lost reply is recognised
    std::string type;
    Severity severity = Severity::Info;
    std::string message;
    nlohmann::json data = nlohmann::json::object();
    std::string occurredAt;

    static Event make(std::string type, Severity severity, std::string message,
                      nlohmann::json data = nlohmann::json::object());
    nlohmann::json toJson() const;
};

/// What waits to be sent: events, and the results of commands that finished
/// while the server was unreachable. Kept on disk (one JSON object per line),
/// so neither is lost to a network outage or a restart.
///
/// When it holds `capacity` entries the oldest *event* is dropped and counted
/// (the count goes in every heartbeat); command results are never dropped.
class Outbox {
public:
    struct Entry {
        std::string kind;  // "event" or "result"
        nlohmann::json body;
    };

    /// An empty `file` keeps everything in memory only (tests, `run --once`)
    Outbox(std::filesystem::path file, std::size_t capacity);

    void add(const Event& event);
    /// A command result, with its id in body["id"]
    void addResult(const nlohmann::json& result);

    /// The oldest `max` entries of one kind, without removing them
    std::vector<Entry> peek(const std::string& kind, std::size_t max) const;
    /// Removes entries of `kind` whose id is in `ids` (after the server accepted them)
    void remove(const std::string& kind, const std::vector<std::string>& ids);

    std::size_t size() const;
    std::size_t dropped() const;

private:
    void load();
    void save() const;

    std::filesystem::path file_;
    std::size_t capacity_;
    mutable std::mutex mutex_;
    std::vector<Entry> entries_;
    std::size_t dropped_ = 0;
};

}  // namespace hha::events
