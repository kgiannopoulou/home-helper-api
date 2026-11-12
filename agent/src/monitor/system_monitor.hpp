#pragma once

#include "monitor/metrics.hpp"

#include <memory>
#include <string>
#include <vector>

namespace hha::monitor {

/// This platform's monitor: windows_monitor.cpp or linux_monitor.cpp.
/// `disks` limits the disks reported to these mount points / drive letters
/// (config monitor.disks); every fixed disk when empty.
std::unique_ptr<SystemMonitor> makeSystemMonitor(std::vector<std::string> disks = {});

/// Keeps only the disks named in `only` (case-insensitive, "C:" matches "C:\").
std::vector<DiskUsage> filterDisks(std::vector<DiskUsage> disks, const std::vector<std::string>& only);

}  // namespace hha::monitor
