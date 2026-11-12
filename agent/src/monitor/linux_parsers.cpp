#include "monitor/linux_parsers.hpp"

#include "core/strings.hpp"

#include <algorithm>
#include <charconv>
#include <fstream>
#include <map>
#include <set>
#include <sstream>

namespace hha::monitor::linux_files {

namespace {

std::optional<std::uint64_t> toU64(std::string_view text) {
    std::uint64_t v = 0;
    const auto [ptr, ec] = std::from_chars(text.data(), text.data() + text.size(), v);
    if (ec != std::errc{} || ptr == text.data()) return std::nullopt;
    return v;
}

std::optional<double> toDouble(std::string_view text) {
    try {
        std::size_t used = 0;
        const auto v = std::stod(std::string(text), &used);
        if (used == 0) return std::nullopt;
        return v;
    } catch (const std::exception&) {
        return std::nullopt;
    }
}

std::vector<std::string_view> words(std::string_view line) {
    std::vector<std::string_view> out;
    std::size_t i = 0;
    while (i < line.size()) {
        while (i < line.size() && (line[i] == ' ' || line[i] == '\t')) ++i;
        const auto start = i;
        while (i < line.size() && line[i] != ' ' && line[i] != '\t') ++i;
        if (i > start) out.push_back(line.substr(start, i - start));
    }
    return out;
}

std::string readSmallFile(const std::filesystem::path& file) {
    std::ifstream in(file);
    std::string text;
    std::getline(in, text);
    return std::string(strings::trim(text));
}

/// /proc/mounts escapes spaces and tabs as \040 and \011
std::string unescapeMount(std::string_view text) {
    std::string out;
    for (std::size_t i = 0; i < text.size(); ++i) {
        if (text[i] == '\\' && i + 3 < text.size()) {
            const auto code = text.substr(i + 1, 3);
            if (code == "040") { out.push_back(' '); i += 3; continue; }
            if (code == "011") { out.push_back('\t'); i += 3; continue; }
            if (code == "134") { out.push_back('\\'); i += 3; continue; }
        }
        out.push_back(text[i]);
    }
    return out;
}

}  // namespace

std::optional<CpuTimes> parseProcStat(std::string_view text) {
    for (auto line : strings::split(text, '\n')) {
        if (!strings::startsWith(line, "cpu ")) continue;
        const auto parts = words(line);
        if (parts.size() < 5) return std::nullopt;
        CpuTimes t;
        // user nice system idle iowait irq softirq steal (guest is already in user)
        for (std::size_t i = 1; i < parts.size() && i <= 8; ++i) {
            const auto v = toU64(parts[i]);
            if (!v) return std::nullopt;
            t.total += *v;
            if (i == 4 || i == 5) t.idle += *v;
        }
        return t;
    }
    return std::nullopt;
}

std::optional<double> cpuPercent(const CpuTimes& before, const CpuTimes& after) {
    if (after.total <= before.total || after.idle < before.idle) return std::nullopt;
    const auto total = static_cast<double>(after.total - before.total);
    const auto idle = static_cast<double>(after.idle - before.idle);
    return std::clamp(100.0 * (total - idle) / total, 0.0, 100.0);
}

std::optional<MemInfo> parseMeminfo(std::string_view text) {
    std::map<std::string, std::uint64_t, std::less<>> kb;
    for (auto line : strings::split(text, '\n')) {
        const auto colon = line.find(':');
        if (colon == std::string_view::npos) continue;
        const auto parts = words(line.substr(colon + 1));
        if (parts.empty()) continue;
        if (const auto v = toU64(parts[0])) kb[std::string(line.substr(0, colon))] = *v;
    }
    const auto get = [&](std::string_view key) -> std::optional<std::uint64_t> {
        if (const auto it = kb.find(key); it != kb.end()) return it->second;
        return std::nullopt;
    };
    const auto total = get("MemTotal");
    if (!total || *total == 0) return std::nullopt;
    MemInfo m;
    m.totalBytes = *total * 1024;
    if (const auto available = get("MemAvailable")) {
        m.availableBytes = *available * 1024;
    } else {
        m.availableBytes = (get("MemFree").value_or(0) + get("Buffers").value_or(0) + get("Cached").value_or(0)) * 1024;
    }
    m.availableBytes = std::min(m.availableBytes, m.totalBytes);
    return m;
}

std::vector<Mount> parseMounts(std::string_view text) {
    static const std::set<std::string, std::less<>> real = {"ext2", "ext3", "ext4", "xfs", "btrfs", "f2fs",
                                                             "vfat", "exfat", "ntfs", "ntfs3", "zfs", "jfs"};
    std::map<std::string, Mount> byDevice;
    for (auto line : strings::split(text, '\n')) {
        const auto parts = words(line);
        if (parts.size() < 3 || !real.contains(parts[2])) continue;
        Mount m{unescapeMount(parts[0]), unescapeMount(parts[1]), std::string(parts[2])};
        if (strings::startsWith(m.mountPoint, "/snap/") || strings::startsWith(m.mountPoint, "/var/lib/docker/")) {
            continue;
        }
        const auto it = byDevice.find(m.device);
        if (it == byDevice.end() || m.mountPoint.size() < it->second.mountPoint.size()) byDevice[m.device] = m;
    }
    std::vector<Mount> out;
    for (auto& [_, m] : byDevice) out.push_back(std::move(m));
    std::sort(out.begin(), out.end(), [](const Mount& a, const Mount& b) { return a.mountPoint < b.mountPoint; });
    return out;
}

std::optional<std::array<double, 3>> parseLoadavg(std::string_view text) {
    const auto parts = words(strings::trim(text));
    if (parts.size() < 3) return std::nullopt;
    std::array<double, 3> load{};
    for (std::size_t i = 0; i < 3; ++i) {
        const auto v = toDouble(parts[i]);
        if (!v) return std::nullopt;
        load[i] = *v;
    }
    return load;
}

std::optional<double> parseUptime(std::string_view text) {
    const auto parts = words(strings::trim(text));
    if (parts.empty()) return std::nullopt;
    return toDouble(parts[0]);
}

std::string parseCpuModel(std::string_view text) {
    std::string fallback;
    for (auto line : strings::split(text, '\n')) {
        const auto colon = line.find(':');
        if (colon == std::string_view::npos) continue;
        const auto key = strings::trim(line.substr(0, colon));
        const auto value = std::string(strings::trim(line.substr(colon + 1)));
        if (key == "model name" && !value.empty()) return value;
        if ((key == "Model" || key == "Hardware") && fallback.empty()) fallback = value;
    }
    return fallback;
}

std::vector<Temperature> readTemperatures(const std::filesystem::path& sysClass) {
    namespace fs = std::filesystem;
    std::vector<Temperature> out;
    std::error_code ec;

    const auto plausible = [](double c) { return c > -40 && c < 150; };

    const auto hwmon = sysClass / "hwmon";
    if (fs::is_directory(hwmon, ec)) {
        std::vector<fs::path> chips;
        for (const auto& entry : fs::directory_iterator(hwmon, ec)) chips.push_back(entry.path());
        std::sort(chips.begin(), chips.end());
        for (const auto& chip : chips) {
            const auto name = readSmallFile(chip / "name");
            std::vector<fs::path> inputs;
            for (const auto& entry : fs::directory_iterator(chip, ec)) {
                const auto file = entry.path().filename().string();
                if (strings::startsWith(file, "temp") && file.size() > 10 && file.substr(file.size() - 6) == "_input") {
                    inputs.push_back(entry.path());
                }
            }
            std::sort(inputs.begin(), inputs.end());
            for (const auto& input : inputs) {
                const auto milli = toDouble(readSmallFile(input));
                if (!milli || !plausible(*milli / 1000.0)) continue;
                auto labelFile = input.string();
                labelFile.replace(labelFile.size() - 6, 6, "_label");
                auto label = readSmallFile(labelFile);
                out.push_back({name.empty() ? label : (label.empty() ? name : name + " " + label), *milli / 1000.0});
            }
        }
    }
    if (!out.empty()) return out;

    const auto thermal = sysClass / "thermal";
    if (fs::is_directory(thermal, ec)) {
        std::vector<fs::path> zones;
        for (const auto& entry : fs::directory_iterator(thermal, ec)) {
            if (strings::startsWith(entry.path().filename().string(), "thermal_zone")) zones.push_back(entry.path());
        }
        std::sort(zones.begin(), zones.end());
        for (const auto& zone : zones) {
            const auto milli = toDouble(readSmallFile(zone / "temp"));
            if (!milli || !plausible(*milli / 1000.0)) continue;
            const auto type = readSmallFile(zone / "type");
            out.push_back({type.empty() ? zone.filename().string() : type, *milli / 1000.0});
        }
    }
    return out;
}

}  // namespace hha::monitor::linux_files
