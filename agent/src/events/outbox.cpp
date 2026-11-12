#include "events/outbox.hpp"

#include "core/paths.hpp"
#include "core/strings.hpp"
#include "core/time.hpp"
#include "crypto/crypto.hpp"
#include "net/api_client.hpp"

#include <spdlog/spdlog.h>

#include <algorithm>
#include <set>

namespace hha::events {

using nlohmann::json;

std::string_view toString(Severity severity) {
    switch (severity) {
        case Severity::Warning: return "warning";
        case Severity::Critical: return "critical";
        default: return "info";
    }
}

Event Event::make(std::string type, Severity severity, std::string message, json data) {
    return Event{crypto::uuid4(), std::move(type), severity, std::move(message), std::move(data), time::nowIso8601()};
}

json Event::toJson() const {
    return {{"id", id},           {"type", type}, {"severity", std::string(toString(severity))},
            {"message", message}, {"data", data}, {"occurred_at", occurredAt}};
}

Outbox::Outbox(std::filesystem::path file, std::size_t capacity) : file_(std::move(file)), capacity_(capacity) {
    load();
}

void Outbox::load() {
    if (file_.empty() || !std::filesystem::exists(file_)) return;
    std::string text;
    try {
        text = paths::readFile(file_);
    } catch (const std::exception& e) {
        spdlog::warn("Outbox unreadable, starting empty: {}", e.what());
        return;
    }
    for (auto line : strings::split(text, '\n')) {
        line = strings::trim(line);
        if (line.empty()) continue;
        const auto j = json::parse(line, nullptr, false);
        // A line cut short by a power cut is skipped, not fatal
        if (j.is_discarded() || !j.is_object()) continue;
        if (j.contains("dropped") && j.size() == 1) {
            dropped_ = j["dropped"].get<std::size_t>();
        } else if (j.contains("kind") && j.contains("body")) {
            entries_.push_back({j["kind"].get<std::string>(), j["body"]});
        }
    }
    if (!entries_.empty()) spdlog::info("Outbox: {} item(s) from before the restart", entries_.size());
}

void Outbox::save() const {
    if (file_.empty()) return;  // memory only
    std::string text;
    if (dropped_ > 0) text += net::dumpJson(json{{"dropped", dropped_}}) + "\n";
    for (const auto& e : entries_) text += net::dumpJson(json{{"kind", e.kind}, {"body", e.body}}) + "\n";
    try {
        paths::writeFileAtomically(file_, text);
    } catch (const std::exception& e) {
        // Still in memory; it will be tried again with the next change
        spdlog::warn("Outbox could not be saved: {}", e.what());
    }
}

void Outbox::add(const Event& event) {
    std::lock_guard lock(mutex_);
    if (entries_.size() >= capacity_) {
        const auto oldest = std::find_if(entries_.begin(), entries_.end(), [](const Entry& e) { return e.kind == "event"; });
        if (oldest != entries_.end()) {
            entries_.erase(oldest);
            ++dropped_;
        }
    }
    entries_.push_back({"event", event.toJson()});
    save();
}

void Outbox::addResult(const json& result) {
    std::lock_guard lock(mutex_);
    entries_.push_back({"result", result});
    save();
}

std::vector<Outbox::Entry> Outbox::peek(const std::string& kind, std::size_t max) const {
    std::lock_guard lock(mutex_);
    std::vector<Entry> out;
    for (const auto& e : entries_) {
        if (out.size() >= max) break;
        if (e.kind == kind) out.push_back(e);
    }
    return out;
}

void Outbox::remove(const std::string& kind, const std::vector<std::string>& ids) {
    std::lock_guard lock(mutex_);
    const std::set<std::string> gone(ids.begin(), ids.end());
    const auto before = entries_.size();
    std::erase_if(entries_, [&](const Entry& e) { return e.kind == kind && gone.contains(e.body.value("id", "")); });
    if (entries_.size() != before) save();
}

std::size_t Outbox::size() const {
    std::lock_guard lock(mutex_);
    return entries_.size();
}

std::size_t Outbox::dropped() const {
    std::lock_guard lock(mutex_);
    return dropped_;
}

}  // namespace hha::events
