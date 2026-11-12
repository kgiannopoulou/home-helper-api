#include "core/host.hpp"

#include "core/strings.hpp"

#ifdef _WIN32
#include <windows.h>
#else
#include <fstream>
#include <sys/utsname.h>
#include <unistd.h>
#endif

namespace hha::host {

std::string hostname() {
#ifdef _WIN32
    wchar_t buffer[MAX_COMPUTERNAME_LENGTH + 1] = {};
    DWORD size = MAX_COMPUTERNAME_LENGTH + 1;
    if (GetComputerNameW(buffer, &size)) return strings::narrow(std::wstring_view(buffer, size));
    return "unknown";
#else
    char buffer[256] = {};
    if (gethostname(buffer, sizeof(buffer) - 1) == 0) return buffer;
    return "unknown";
#endif
}

std::string osName() {
#ifdef _WIN32
    return "Windows";
#elif defined(__APPLE__)
    return "macOS";
#else
    return "Linux";
#endif
}

std::string osVersion() {
#ifdef _WIN32
    // GetVersionEx lies without a manifest; RtlGetVersion does not
    using RtlGetVersionFn = LONG(WINAPI*)(PRTL_OSVERSIONINFOW);
    if (const auto ntdll = GetModuleHandleW(L"ntdll.dll")) {
        if (const auto fn = reinterpret_cast<RtlGetVersionFn>(GetProcAddress(ntdll, "RtlGetVersion"))) {
            RTL_OSVERSIONINFOW info{};
            info.dwOSVersionInfoSize = sizeof(info);
            if (fn(&info) == 0) {
                return std::to_string(info.dwMajorVersion) + "." + std::to_string(info.dwMinorVersion) + "." +
                       std::to_string(info.dwBuildNumber);
            }
        }
    }
    return "unknown";
#else
    std::string version;
    utsname u{};
    if (uname(&u) == 0) version = u.release;
    std::ifstream release("/etc/os-release");
    std::string line;
    while (std::getline(release, line)) {
        if (strings::startsWith(line, "PRETTY_NAME=")) {
            auto name = line.substr(12);
            if (name.size() >= 2 && name.front() == '"' && name.back() == '"') name = name.substr(1, name.size() - 2);
            return name + " (kernel " + version + ")";
        }
    }
    return version.empty() ? "unknown" : version;
#endif
}

}  // namespace hha::host
