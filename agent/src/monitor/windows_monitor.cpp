// Windows: Win32 for CPU, memory and disks; WMI for temperatures, which
// Windows only exposes through ACPI thermal zones (and not on every machine).
#include "monitor/system_monitor.hpp"

#include "core/host.hpp"
#include "core/strings.hpp"

#include <spdlog/spdlog.h>

#include <windows.h>
#include <comdef.h>
#include <wbemidl.h>

#include <algorithm>
#include <mutex>

namespace hha::monitor {

namespace {

std::uint64_t ticks(const FILETIME& ft) {
    return (static_cast<std::uint64_t>(ft.dwHighDateTime) << 32) | ft.dwLowDateTime;
}

struct CpuTimes {
    std::uint64_t idle = 0;
    std::uint64_t total = 0;  // kernel (which includes idle) + user
};

std::optional<CpuTimes> readCpu() {
    FILETIME idle{}, kernel{}, user{};
    if (!GetSystemTimes(&idle, &kernel, &user)) return std::nullopt;
    return CpuTimes{ticks(idle), ticks(kernel) + ticks(user)};
}

std::string registryString(HKEY root, const wchar_t* key, const wchar_t* value) {
    wchar_t buffer[256] = {};
    DWORD size = sizeof(buffer);
    if (RegGetValueW(root, key, value, RRF_RT_REG_SZ, nullptr, buffer, &size) != ERROR_SUCCESS) return {};
    return std::string(strings::trim(strings::narrow(buffer)));
}

std::vector<DiskUsage> fixedDisks() {
    std::vector<DiskUsage> out;
    const DWORD drives = GetLogicalDrives();
    for (int i = 0; i < 26; ++i) {
        if (!(drives & (1u << i))) continue;
        const std::wstring root = std::wstring(1, static_cast<wchar_t>(L'A' + i)) + L":\\";
        if (GetDriveTypeW(root.c_str()) != DRIVE_FIXED) continue;
        ULARGE_INTEGER available{}, total{}, free{};
        if (!GetDiskFreeSpaceExW(root.c_str(), &available, &total, &free) || total.QuadPart == 0) continue;
        wchar_t fs[MAX_PATH + 1] = {};
        GetVolumeInformationW(root.c_str(), nullptr, 0, nullptr, nullptr, nullptr, fs, MAX_PATH + 1);
        out.push_back({strings::narrow(root), total.QuadPart, available.QuadPart, strings::narrow(fs)});
    }
    return out;
}

/// ACPI thermal zones over WMI. The performance counter class works without
/// administrator rights on most machines; MSAcpi_ThermalZoneTemperature needs
/// them (the service runs as SYSTEM, so it has them). Both report tenths of a kelvin.
class ThermalZones {
public:
    std::vector<Temperature> read() {
        const auto now = std::chrono::steady_clock::now();
        if (unavailableUntil_ && now < *unavailableUntil_) return {};

        const HRESULT init = CoInitializeEx(nullptr, COINIT_MULTITHREADED);
        const bool uninit = SUCCEEDED(init);
        std::vector<Temperature> out = query(L"ROOT\\CIMV2",
                                             L"SELECT Name, HighPrecisionTemperature FROM "
                                             L"Win32_PerfFormattedData_Counters_ThermalZoneInformation",
                                             L"Name", L"HighPrecisionTemperature");
        if (out.empty()) {
            out = query(L"ROOT\\WMI", L"SELECT InstanceName, CurrentTemperature FROM MSAcpi_ThermalZoneTemperature",
                        L"InstanceName", L"CurrentTemperature");
        }
        if (uninit) CoUninitialize();

        if (out.empty()) {
            // Most desktops have no ACPI thermal zone: don't ask WMI every 30 seconds
            if (!unavailableUntil_) spdlog::info("No temperature sensors visible to Windows (ACPI thermal zones)");
            unavailableUntil_ = now + std::chrono::minutes(30);
        }
        return out;
    }

private:
    static std::vector<Temperature> query(const wchar_t* ns, const wchar_t* wql, const wchar_t* nameProp,
                                          const wchar_t* tempProp) {
        std::vector<Temperature> out;
        IWbemLocator* locator = nullptr;
        if (FAILED(CoCreateInstance(CLSID_WbemLocator, nullptr, CLSCTX_INPROC_SERVER, IID_IWbemLocator,
                                    reinterpret_cast<void**>(&locator)))) {
            return out;
        }
        IWbemServices* services = nullptr;
        if (SUCCEEDED(locator->ConnectServer(_bstr_t(ns), nullptr, nullptr, nullptr, 0, nullptr, nullptr, &services))) {
            CoSetProxyBlanket(services, RPC_C_AUTHN_WINNT, RPC_C_AUTHZ_NONE, nullptr, RPC_C_AUTHN_LEVEL_CALL,
                              RPC_C_IMP_LEVEL_IMPERSONATE, nullptr, EOAC_NONE);
            IEnumWbemClassObject* rows = nullptr;
            if (SUCCEEDED(services->ExecQuery(_bstr_t(L"WQL"), _bstr_t(wql),
                                              WBEM_FLAG_FORWARD_ONLY | WBEM_FLAG_RETURN_IMMEDIATELY, nullptr, &rows))) {
                IWbemClassObject* row = nullptr;
                ULONG returned = 0;
                while (rows->Next(2000, 1, &row, &returned) == WBEM_S_NO_ERROR && returned == 1) {
                    VARIANT name, temp;
                    VariantInit(&name);
                    VariantInit(&temp);
                    if (SUCCEEDED(row->Get(nameProp, 0, &name, nullptr, nullptr)) &&
                        SUCCEEDED(row->Get(tempProp, 0, &temp, nullptr, nullptr)) &&
                        SUCCEEDED(VariantChangeType(&temp, &temp, 0, VT_R8))) {
                        const double celsius = temp.dblVal / 10.0 - 273.15;
                        std::string label = name.vt == VT_BSTR ? strings::narrow(name.bstrVal) : "Thermal zone";
                        // "\_TZ.TZ00" → "TZ00"
                        if (const auto dot = label.rfind('.'); dot != std::string::npos) label = label.substr(dot + 1);
                        if (celsius > -40 && celsius < 150) out.push_back({label, celsius});
                    }
                    VariantClear(&name);
                    VariantClear(&temp);
                    row->Release();
                }
                rows->Release();
            }
            services->Release();
        }
        locator->Release();
        return out;
    }

    std::optional<std::chrono::steady_clock::time_point> unavailableUntil_;
};

class WindowsMonitor final : public SystemMonitor {
public:
    explicit WindowsMonitor(std::vector<std::string> disks) : only_(std::move(disks)), previous_(readCpu()) {}

    Metrics sample() override {
        std::lock_guard lock(mutex_);
        Metrics m;
        if (const auto now = readCpu()) {
            if (previous_ && now->total > previous_->total) {
                const auto total = static_cast<double>(now->total - previous_->total);
                const auto idle = static_cast<double>(now->idle - previous_->idle);
                m.cpuPercent = std::clamp(100.0 * (total - idle) / total, 0.0, 100.0);
            }
            previous_ = now;
        }

        MEMORYSTATUSEX mem{};
        mem.dwLength = sizeof(mem);
        if (GlobalMemoryStatusEx(&mem)) {
            m.memoryTotalBytes = mem.ullTotalPhys;
            m.memoryUsedBytes = mem.ullTotalPhys - mem.ullAvailPhys;
        }
        m.disks = filterDisks(fixedDisks(), only_);
        m.temperatures = thermal_.read();
        m.systemUptime = std::chrono::seconds(GetTickCount64() / 1000);
        return m;
    }

    SystemInfo info() override {
        SystemInfo i;
        i.hostname = host::hostname();
        i.osName = host::osName();
        i.osVersion = host::osVersion();
        i.cpuModel = registryString(HKEY_LOCAL_MACHINE, L"HARDWARE\\DESCRIPTION\\System\\CentralProcessor\\0",
                                    L"ProcessorNameString");
        i.cpuCores = GetActiveProcessorCount(ALL_PROCESSOR_GROUPS);
        MEMORYSTATUSEX mem{};
        mem.dwLength = sizeof(mem);
        if (GlobalMemoryStatusEx(&mem)) i.memoryBytes = mem.ullTotalPhys;
        i.disks = filterDisks(fixedDisks(), only_);
        return i;
    }

private:
    std::vector<std::string> only_;
    std::mutex mutex_;
    std::optional<CpuTimes> previous_;
    ThermalZones thermal_;
};

}  // namespace

std::unique_ptr<SystemMonitor> makeSystemMonitor(std::vector<std::string> disks) {
    return std::make_unique<WindowsMonitor>(std::move(disks));
}

}  // namespace hha::monitor
