#pragma once

#include "agent/config.hpp"
#include "events/outbox.hpp"
#include "monitor/metrics.hpp"

#include <map>
#include <string>
#include <vector>

namespace hha::events {

/// Turns readings into events: "cpu.high" after `sustainSamples` readings over
/// the line, "cpu.normal" after as many back under it (minus a margin, so a
/// value hovering at the line doesn't flap). The same for memory, each disk's
/// free space and the hottest sensor. A brief spike is never an event.
class ThresholdWatcher {
public:
    explicit ThresholdWatcher(agent::Thresholds thresholds) : t_(thresholds) {}

    std::vector<Event> feed(const monitor::Metrics& metrics);

private:
    struct State {
        bool high = false;
        int over = 0;
        int under = 0;
    };

    /// value > line is "high"; back to normal under line - margin
    void check(const std::string& key, double value, double line, double margin, const std::string& highType,
               const std::string& normalType, Severity severity, const std::string& highMessage,
               const std::string& normalMessage, nlohmann::json data, std::vector<Event>& out);

    agent::Thresholds t_;
    std::map<std::string, State> states_;
};

}  // namespace hha::events
