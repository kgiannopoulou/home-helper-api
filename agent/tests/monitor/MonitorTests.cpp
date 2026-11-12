#include "monitor/linux_parsers.hpp"
#include "monitor/system_monitor.hpp"

#include "TempDir.hpp"
#include "core/paths.hpp"

#include <catch2/catch_approx.hpp>
#include <catch2/catch_test_macros.hpp>

#include <thread>

using namespace hha;
using namespace hha::monitor;
using Catch::Approx;

TEST_CASE("linux: CPU usage from two /proc/stat readings") {
    const auto before = linux_files::parseProcStat(
        "cpu  100 0 100 700 100 0 0 0 0 0\ncpu0 50 0 50 350 50 0 0 0 0 0\nintr 1\n");
    const auto after = linux_files::parseProcStat("cpu  200 0 150 1000 150 0 0 0 0 0\n");
    REQUIRE(before);
    REQUIRE(after);
    CHECK(before->total == 1000);
    CHECK(before->idle == 800);  // idle + iowait
    // 500 jiffies passed, 350 of them idle
    CHECK(linux_files::cpuPercent(*before, *after) == Approx(30.0));
    CHECK_FALSE(linux_files::cpuPercent(*after, *after));
    CHECK_FALSE(linux_files::parseProcStat("nothing here"));
}

TEST_CASE("linux: memory from /proc/meminfo, old kernels too") {
    const auto m = linux_files::parseMeminfo("MemTotal:  8000000 kB\nMemFree: 1000000 kB\nMemAvailable: 6000000 kB\n");
    REQUIRE(m);
    CHECK(m->totalBytes == 8'192'000'000ULL);
    CHECK(m->availableBytes == 6'144'000'000ULL);

    const auto old = linux_files::parseMeminfo("MemTotal: 1000 kB\nMemFree: 100 kB\nBuffers: 50 kB\nCached: 250 kB\n");
    REQUIRE(old);
    CHECK(old->availableBytes == 400 * 1024);
    CHECK_FALSE(linux_files::parseMeminfo("MemFree: 1 kB"));
}

TEST_CASE("linux: real disks from /proc/mounts, one per device") {
    const auto mounts = linux_files::parseMounts(
        "sysfs /sys sysfs rw 0 0\n"
        "proc /proc proc rw 0 0\n"
        "/dev/sda2 / ext4 rw,relatime 0 0\n"
        "/dev/sda1 /boot/efi vfat rw 0 0\n"
        "/dev/sda2 /var/lib/docker/overlay ext4 rw 0 0\n"
        "/dev/loop3 /snap/core/123 squashfs ro 0 0\n"
        "tmpfs /run tmpfs rw 0 0\n"
        "/dev/sdb1 /mnt/media\\040disk xfs rw 0 0\n");
    REQUIRE(mounts.size() == 3);
    CHECK(mounts[0].mountPoint == "/");
    CHECK(mounts[0].filesystem == "ext4");
    CHECK(mounts[1].mountPoint == "/boot/efi");
    CHECK(mounts[2].mountPoint == "/mnt/media disk");
}

TEST_CASE("linux: load, uptime and CPU model") {
    CHECK(linux_files::parseLoadavg("0.52 0.41 0.30 1/234 5678\n") == std::array<double, 3>{0.52, 0.41, 0.30});
    CHECK(linux_files::parseUptime("3600.25 7000.00\n") == Approx(3600.25));
    CHECK(linux_files::parseCpuModel("processor: 0\nmodel name\t: Intel(R) N100\n") == "Intel(R) N100");
    CHECK(linux_files::parseCpuModel("processor: 0\nModel\t\t: Raspberry Pi 5 Model B Rev 1.0\n") ==
          "Raspberry Pi 5 Model B Rev 1.0");
}

TEST_CASE("linux: temperatures from hwmon, thermal zones as the fallback") {
    test::TempDir sys;
    const auto write = [&](const std::filesystem::path& p, const std::string& text) {
        paths::writeFileAtomically(sys.path() / p, text + "\n");
    };

    write("thermal/thermal_zone0/type", "acpitz");
    write("thermal/thermal_zone0/temp", "41000");
    auto temps = linux_files::readTemperatures(sys.path());
    REQUIRE(temps.size() == 1);
    CHECK(temps[0].label == "acpitz");
    CHECK(temps[0].celsius == Approx(41.0));

    write("hwmon/hwmon0/name", "coretemp");
    write("hwmon/hwmon0/temp1_input", "54000");
    write("hwmon/hwmon0/temp1_label", "Package id 0");
    write("hwmon/hwmon1/name", "nvme");
    write("hwmon/hwmon1/temp1_input", "61250");
    write("hwmon/hwmon1/temp2_input", "-273000");  // a glitch: dropped
    temps = linux_files::readTemperatures(sys.path());
    REQUIRE(temps.size() == 2);
    CHECK(temps[0].label == "coretemp Package id 0");
    CHECK(temps[1].label == "nvme");
    CHECK(temps[1].celsius == Approx(61.25));

    CHECK(linux_files::readTemperatures(sys.path() / "missing").empty());
}

TEST_CASE("monitor: only the configured disks") {
    std::vector<DiskUsage> disks = {{"C:\\", 1, 1, ""}, {"D:\\", 1, 1, ""}, {"/", 1, 1, ""}};
    CHECK(filterDisks(disks, {}).size() == 3);
    const auto only = filterDisks(disks, {"c:", "/"});
    REQUIRE(only.size() == 2);
    CHECK(only[0].mount == "C:\\");
    CHECK(only[1].mount == "/");
}

TEST_CASE("monitor: this computer's real readings are plausible") {
    auto monitor = makeSystemMonitor();
    monitor->sample();
    std::this_thread::sleep_for(std::chrono::milliseconds(250));
    const auto m = monitor->sample();

    REQUIRE(m.cpuPercent.has_value());
    CHECK(*m.cpuPercent >= 0);
    CHECK(*m.cpuPercent <= 100);
    REQUIRE(m.memoryTotalBytes.has_value());
    CHECK(*m.memoryTotalBytes > 100'000'000ULL);
    CHECK(*m.memoryUsedBytes <= *m.memoryTotalBytes);
    REQUIRE_FALSE(m.disks.empty());
    for (const auto& d : m.disks) {
        CHECK(d.totalBytes > 0);
        CHECK(d.freeBytes <= d.totalBytes);
    }
    for (const auto& t : m.temperatures) {
        CHECK(t.celsius > -40);
        CHECK(t.celsius < 150);
    }

    const auto info = monitor->info();
    CHECK_FALSE(info.hostname.empty());
    CHECK(info.cpuCores > 0);
    CHECK(info.memoryBytes == *m.memoryTotalBytes);
}
