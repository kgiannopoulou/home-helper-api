#include "core/paths.hpp"

#include <fstream>
#include <sstream>
#include <stdexcept>
#include <string>
#include <system_error>

#ifdef _WIN32
#include <windows.h>
#include <shlobj.h>
#else
#include <unistd.h>
#endif

namespace hha::paths {

namespace {

#ifdef _WIN32
std::filesystem::path programData() {
    PWSTR raw = nullptr;
    std::filesystem::path base = L"C:\\ProgramData";
    if (SUCCEEDED(SHGetKnownFolderPath(FOLDERID_ProgramData, 0, nullptr, &raw))) {
        base = raw;
    }
    CoTaskMemFree(raw);
    return base / L"HomeHelperAgent";
}
#endif

}  // namespace

std::filesystem::path defaultConfigFile() {
#ifdef _WIN32
    return programData() / L"agent.json";
#else
    return "/etc/home-helper-agent/agent.json";
#endif
}

std::filesystem::path defaultDataDir() {
#ifdef _WIN32
    return programData() / L"data";
#else
    return "/var/lib/home-helper-agent";
#endif
}

std::filesystem::path currentExecutable() {
#ifdef _WIN32
    std::wstring buffer(MAX_PATH, L'\0');
    while (true) {
        const DWORD n = GetModuleFileNameW(nullptr, buffer.data(), static_cast<DWORD>(buffer.size()));
        if (n == 0) {
            throw std::system_error(static_cast<int>(GetLastError()), std::system_category(), "GetModuleFileNameW");
        }
        if (n < buffer.size()) {
            buffer.resize(n);
            return buffer;
        }
        buffer.resize(buffer.size() * 2);
    }
#else
    return std::filesystem::read_symlink("/proc/self/exe");
#endif
}

void writeFileAtomically(const std::filesystem::path& target, std::string_view contents) {
    if (target.has_parent_path()) {
        std::filesystem::create_directories(target.parent_path());
    }
    auto temporary = target;
    temporary += ".tmp";
    {
        std::ofstream out(temporary, std::ios::binary | std::ios::trunc);
        if (!out) {
            throw std::runtime_error("cannot write " + temporary.string());
        }
        out.write(contents.data(), static_cast<std::streamsize>(contents.size()));
        out.flush();
        if (!out) {
            throw std::runtime_error("cannot write " + temporary.string());
        }
    }
    std::filesystem::rename(temporary, target);  // replaces the target on both platforms
}

std::string readFile(const std::filesystem::path& file) {
    std::ifstream in(file, std::ios::binary);
    if (!in) {
        throw std::runtime_error("cannot read " + file.string());
    }
    std::ostringstream buffer;
    buffer << in.rdbuf();
    return buffer.str();
}

}  // namespace hha::paths
