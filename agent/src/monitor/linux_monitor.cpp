// Linux (and the Raspberry Pi): everything from /proc, /sys and statvfs(3).
#include "monitor/linux_parsers.hpp"
#include "monitor/system_monitor.hpp"

#include "core/host.hpp"
#include "core/paths.hpp"

#include <spdlog/spdlog.h>

#include <mutex>
#include <thread>

#include <sys/statvfs.h>

namespace hha::monitor {

namespace {

std::string readOr(const char* file) {
    try {
        return paths::readFile(file);
    } catch (const std::exception&) {
        return {};
    }
}

class LinuxMonitor final : public SystemMonitor {
public:
    explicit LinuxMonitor(std::vector<std::string> disks) : only_(std::move(disks)) {
        previous_ = linux_files::parseProcStat(readOr("/proc/stat"));
    }

    Metrics sample() override {
        std::lock_guard lock(mutex_);
        Metrics m;

        const auto now = linux_files::parseProcStat(readOr("/proc/stat"));
        if (now && previous_) m.cpuPercent = linux_files::cpuPercent(*previous_, *now);
        if (now) previous_ = now;

        if (const auto mem = linux_files::parseMeminfo(readOr("/proc/meminfo"))) {
            m.memoryTotalBytes = mem->totalBytes;
            m.memoryUsedBytes = mem->totalBytes - mem->availableBytes;
        }
        m.disks = disks();
        m.temperatures = linux_files::readTemperatures("/sys/class");
        m.load = linux_files::parseLoadavg(readOr("/proc/loadavg"));
        if (const auto up = linux_files::parseUptime(readOr("/proc/uptime"))) {
            m.systemUptime = std::chrono::seconds(static_cast<long long>(*up));
        }
        return m;
    }

    SystemInfo info() override {
        SystemInfo i;
        i.hostname = host::hostname();
        i.osName = host::osName();
        i.osVersion = host::osVersion();
        i.cpuModel = linux_files::parseCpuModel(readOr("/proc/cpuinfo"));
        i.cpuCores = std::thread::hardware_concurrency();
        if (const auto mem = linux_files::parseMeminfo(readOr("/proc/meminfo"))) i.memoryBytes = mem->totalBytes;
        i.disks = disks();
        return i;
    }

private:
    std::vector<DiskUsage> disks() const {
        std::vector<DiskUsage> out;
        for (const auto& mount : linux_files::parseMounts(readOr("/proc/mounts"))) {
            struct statvfs s {};
            if (::statvfs(mount.mountPoint.c_str(), &s) != 0 || s.f_blocks == 0) continue;
            const auto block = static_cast<std::uint64_t>(s.f_frsize ? s.f_frsize : s.f_bsize);
            // f_bavail: what an unprivileged user can still write, as df shows it
            out.push_back({mount.mountPoint, s.f_blocks * block, s.f_bavail * block, mount.filesystem});
        }
        return filterDisks(std::move(out), only_);
    }

    std::vector<std::string> only_;
    std::mutex mutex_;
    std::optional<linux_files::CpuTimes> previous_;
};

}  // namespace

std::unique_ptr<SystemMonitor> makeSystemMonitor(std::vector<std::string> disks) {
    return std::make_unique<LinuxMonitor>(std::move(disks));
}

}  // namespace hha::monitor
