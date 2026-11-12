#include "monitor/metrics.hpp"

#include <algorithm>
#include <cmath>

namespace hha::monitor {

namespace {
double round2(double v) {
    return std::round(v * 100.0) / 100.0;
}
}  // namespace

double DiskUsage::usedPercent() const {
    if (totalBytes == 0) return 0;
    const auto free = std::min(freeBytes, totalBytes);
    return 100.0 * static_cast<double>(totalBytes - free) / static_cast<double>(totalBytes);
}

std::optional<double> Metrics::memoryPercent() const {
    if (!memoryTotalBytes || !memoryUsedBytes || *memoryTotalBytes == 0) return std::nullopt;
    return 100.0 * static_cast<double>(*memoryUsedBytes) / static_cast<double>(*memoryTotalBytes);
}

std::optional<double> Metrics::hottest() const {
    if (temperatures.empty()) return std::nullopt;
    return std::max_element(temperatures.begin(), temperatures.end(),
                            [](const auto& a, const auto& b) { return a.celsius < b.celsius; })
        ->celsius;
}

nlohmann::json Metrics::toJson() const {
    nlohmann::json j = nlohmann::json::object();
    if (cpuPercent) j["cpu_percent"] = round2(std::clamp(*cpuPercent, 0.0, 100.0));
    if (memoryTotalBytes && memoryUsedBytes) {
        j["memory"] = {{"total_bytes", *memoryTotalBytes}, {"used_bytes", *memoryUsedBytes}};
    }
    if (!disks.empty()) {
        j["disks"] = nlohmann::json::array();
        for (const auto& d : disks) {
            j["disks"].push_back({{"mount", d.mount}, {"total_bytes", d.totalBytes}, {"free_bytes", d.freeBytes}});
        }
    }
    if (!temperatures.empty()) {
        j["temperatures"] = nlohmann::json::array();
        for (const auto& t : temperatures) {
            j["temperatures"].push_back({{"label", t.label}, {"celsius", std::round(t.celsius * 10.0) / 10.0}});
        }
    }
    if (load) j["load"] = {round2((*load)[0]), round2((*load)[1]), round2((*load)[2])};
    return j;
}

}  // namespace hha::monitor

#include "monitor/system_monitor.hpp"

#include "core/strings.hpp"

namespace hha::monitor {

std::vector<DiskUsage> filterDisks(std::vector<DiskUsage> disks, const std::vector<std::string>& only) {
    if (only.empty()) return disks;
    const auto key = [](std::string_view mount) {
        auto k = strings::toLower(mount);
        while (k.size() > 1 && (k.back() == '\\' || k.back() == '/')) k.pop_back();
        return k;
    };
    std::vector<DiskUsage> out;
    for (auto& d : disks) {
        for (const auto& wanted : only) {
            if (key(d.mount) == key(wanted)) {
                out.push_back(std::move(d));
                break;
            }
        }
    }
    return out;
}

}  // namespace hha::monitor
