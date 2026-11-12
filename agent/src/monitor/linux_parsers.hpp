#pragma once

#include "monitor/metrics.hpp"

#include <array>
#include <cstdint>
#include <filesystem>
#include <optional>
#include <string>
#include <string_view>
#include <vector>

/// Parsers for what Linux exposes in /proc and /sys. Pure functions of text or
/// of a directory, so they are tested on every platform with sample files.
namespace hha::monitor::linux_files {

/// Jiffies from the "cpu " line of /proc/stat
struct CpuTimes {
    std::uint64_t total = 0;
    std::uint64_t idle = 0;  // idle + iowait
};

std::optional<CpuTimes> parseProcStat(std::string_view text);

/// Busy share between two readings, 0–100; nullopt when no time passed
std::optional<double> cpuPercent(const CpuTimes& before, const CpuTimes& after);

struct MemInfo {
    std::uint64_t totalBytes = 0;
    std::uint64_t availableBytes = 0;
};

/// MemTotal and MemAvailable (kernel 3.14+; falls back to MemFree + Buffers + Cached)
std::optional<MemInfo> parseMeminfo(std::string_view text);

struct Mount {
    std::string device;
    std::string mountPoint;
    std::string filesystem;
};

/// Real disks from /proc/mounts: one entry per device, the shortest mount
/// point wins; pseudo filesystems, snaps and containers' overlays left out.
std::vector<Mount> parseMounts(std::string_view text);

std::optional<std::array<double, 3>> parseLoadavg(std::string_view text);
std::optional<double> parseUptime(std::string_view text);

/// "model name" (x86) or "Model" (Raspberry Pi) from /proc/cpuinfo
std::string parseCpuModel(std::string_view text);

/// hwmon sensors (coretemp, k10temp, nvme, cpu_thermal…) under `sysClass`
/// (normally /sys/class), with thermal zones as a fallback when there are none.
/// Readings outside -40…150 °C are sensor glitches and dropped.
std::vector<Temperature> readTemperatures(const std::filesystem::path& sysClass);

}  // namespace hha::monitor::linux_files
