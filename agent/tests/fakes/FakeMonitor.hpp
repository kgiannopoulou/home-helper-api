#pragma once

#include "monitor/metrics.hpp"

#include <mutex>
#include <stdexcept>

namespace hha::test {

/// Readings set by the test.
class FakeMonitor final : public monitor::SystemMonitor {
public:
    FakeMonitor() {
        metrics.cpuPercent = 10;
        metrics.memoryTotalBytes = 16'000'000'000ULL;
        metrics.memoryUsedBytes = 4'000'000'000ULL;
        metrics.disks = {{"C:\\", 500'000'000'000ULL, 250'000'000'000ULL, "NTFS"}};
        metrics.temperatures = {{"CPU", 50}};
        system.hostname = "TESTPC";
        system.osName = "Windows";
        system.osVersion = "10.0.26300";
        system.cpuModel = "Test CPU";
        system.cpuCores = 4;
        system.memoryBytes = 16'000'000'000ULL;
        system.disks = metrics.disks;
    }

    monitor::Metrics sample() override {
        std::lock_guard lock(mutex);
        ++samples;
        if (broken) throw std::runtime_error("sensor fell off");
        return metrics;
    }

    monitor::SystemInfo info() override {
        std::lock_guard lock(mutex);
        return system;
    }

    std::mutex mutex;
    monitor::Metrics metrics;
    monitor::SystemInfo system;
    int samples = 0;
    bool broken = false;
};

}  // namespace hha::test
