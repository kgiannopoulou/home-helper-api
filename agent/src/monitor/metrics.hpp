#pragma once

#include <nlohmann/json.hpp>

#include <array>
#include <chrono>
#include <cstdint>
#include <optional>
#include <string>
#include <vector>

namespace hha::monitor {

struct DiskUsage {
    std::string mount;       // "C:\" or "/"
    std::uint64_t totalBytes = 0;
    std::uint64_t freeBytes = 0;
    std::string filesystem;  // "NTFS", "ext4"; inventory only

    double usedPercent() const;
    double freePercent() const { return totalBytes ? 100.0 - usedPercent() : 0; }
};

struct Temperature {
    std::string label;  // "CPU", "acpitz", "nvme Composite"
    double celsius = 0;
};

/// One reading of the computer, as the heartbeat sends it (docs/protocol.md#post-heartbeat).
/// Anything the platform can't tell is left empty and left out of the JSON.
struct Metrics {
    std::optional<double> cpuPercent;
    std::optional<std::uint64_t> memoryTotalBytes;
    std::optional<std::uint64_t> memoryUsedBytes;
    std::vector<DiskUsage> disks;
    std::vector<Temperature> temperatures;
    std::optional<std::array<double, 3>> load;  // Linux load averages
    std::optional<std::chrono::seconds> systemUptime;

    std::optional<double> memoryPercent() const;
    std::optional<double> hottest() const;

    nlohmann::json toJson() const;
};

/// What the computer is, for the inventory (changes rarely).
struct SystemInfo {
    std::string hostname;
    std::string osName;
    std::string osVersion;
    std::string cpuModel;
    unsigned cpuCores = 0;
    std::uint64_t memoryBytes = 0;
    std::vector<DiskUsage> disks;
};

/// Reads the computer. One per platform (windows_monitor.cpp, linux_monitor.cpp);
/// tests use a fake.
class SystemMonitor {
public:
    virtual ~SystemMonitor() = default;
    /// CPU usage is measured since the previous call, so call it at a steady pace.
    virtual Metrics sample() = 0;
    virtual SystemInfo info() = 0;
};

}  // namespace hha::monitor
