#include "events/thresholds.hpp"

#include <cmath>
#include <cstdio>

namespace hha::events {

namespace {

std::string fixed(double v, int decimals = 0) {
    char buffer[32];
    std::snprintf(buffer, sizeof(buffer), "%.*f", decimals, v);
    return buffer;
}

std::string gigabytes(std::uint64_t bytes) {
    return fixed(static_cast<double>(bytes) / 1e9, 1) + " GB";
}

}  // namespace

void ThresholdWatcher::check(const std::string& key, double value, double line, double margin,
                             const std::string& highType, const std::string& normalType, Severity severity,
                             const std::string& highMessage, const std::string& normalMessage, nlohmann::json data,
                             std::vector<Event>& out) {
    auto& s = states_[key];
    if (value > line) {
        s.under = 0;
        if (!s.high && ++s.over >= t_.sustainSamples) {
            s.high = true;
            out.push_back(Event::make(highType, severity, highMessage, data));
        }
    } else if (value <= line - margin) {
        s.over = 0;
        if (s.high && ++s.under >= t_.sustainSamples) {
            s.high = false;
            out.push_back(Event::make(normalType, Severity::Info, normalMessage, data));
        }
    } else {
        // In the margin: neither counts
        s.over = 0;
        s.under = 0;
    }
}

std::vector<Event> ThresholdWatcher::feed(const monitor::Metrics& m) {
    std::vector<Event> out;
    if (m.cpuPercent) {
        const auto v = *m.cpuPercent;
        check("cpu", v, t_.cpuHighPercent, 10, "cpu.high", "cpu.normal", Severity::Warning,
              "CPU at " + fixed(v) + "% for a while", "CPU back to " + fixed(v) + "%", {{"cpu_percent", v}}, out);
    }
    if (const auto mem = m.memoryPercent()) {
        check("memory", *mem, t_.memoryHighPercent, 5, "memory.high", "memory.normal", Severity::Warning,
              "Memory " + fixed(*mem) + "% used", "Memory back to " + fixed(*mem) + "% used", {{"memory_percent", *mem}},
              out);
    }
    for (const auto& d : m.disks) {
        if (d.totalBytes == 0) continue;
        // For disks the line is free space: "high" means low on space
        const auto used = d.usedPercent();
        const auto line = 100.0 - t_.diskLowFreePercent;
        const auto severity = d.freePercent() < t_.diskLowFreePercent / 2 ? Severity::Critical : Severity::Warning;
        check("disk:" + d.mount, used, line, 2, "disk.low", "disk.normal", severity,
              d.mount + " has " + gigabytes(d.freeBytes) + " free (" + fixed(d.freePercent()) + "%)",
              d.mount + " has " + gigabytes(d.freeBytes) + " free again (" + fixed(d.freePercent()) + "%)",
              {{"mount", d.mount}, {"free_bytes", d.freeBytes}, {"free_percent", std::round(d.freePercent() * 10) / 10}},
              out);
    }
    if (const auto hottest = m.hottest()) {
        std::string label;
        for (const auto& t : m.temperatures) {
            if (t.celsius == *hottest) label = t.label;
        }
        const auto severity = *hottest >= t_.temperatureHighCelsius + 10 ? Severity::Critical : Severity::Warning;
        check("temperature", *hottest, t_.temperatureHighCelsius, 5, "temperature.high", "temperature.normal",
              severity, label + " at " + fixed(*hottest) + " °C", label + " back to " + fixed(*hottest) + " °C",
              {{"sensor", label}, {"celsius", *hottest}}, out);
    }
    return out;
}

}  // namespace hha::events
