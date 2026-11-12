#pragma once

#include <chrono>
#include <filesystem>
#include <functional>
#include <string>
#include <vector>

namespace hha::commands {

struct ProcessResult {
    int exitCode = -1;
    /// stdout and stderr together, in the order they were written, cut at the limit
    std::string output;
    bool truncated = false;
    bool timedOut = false;
    std::chrono::milliseconds duration{0};
};

/// Starts `program` with exactly `args`: no shell, no PATH lookup, no
/// window, stdin closed. On timeout the whole process tree is killed (a Job
/// object on Windows, the process group on Linux). Throws std::runtime_error
/// when the program can't be started at all.
ProcessResult runProcess(const std::filesystem::path& program, const std::vector<std::string>& args,
                         std::chrono::milliseconds timeout, std::size_t maxOutputBytes);

/// How the executor starts programs; tests substitute it.
using ProcessRunner = std::function<ProcessResult(const std::filesystem::path&, const std::vector<std::string>&,
                                                  std::chrono::milliseconds, std::size_t)>;

/// One argument for a Windows command line, quoted so that CommandLineToArgvW
/// and the MSVC runtime give the program back exactly this string.
std::wstring quoteWindowsArgument(const std::wstring& arg);

}  // namespace hha::commands
