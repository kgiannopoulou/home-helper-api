#pragma once

#include <filesystem>
#include <optional>
#include <string>
#include <string_view>

namespace hha::logging {

struct Options {
    /// trace, debug, info, warn, error, critical, off
    std::string level = "info";
    /// Rotating file (5 MB × 3) in addition to the console; none when empty
    std::optional<std::filesystem::path> file;
    /// The Windows service has no console
    bool console = true;
};

/// Replaces spdlog's default logger: "[2026-11-12 10:30:01.123] [info] text".
/// Safe to call again (e.g. once the config file has been read).
void install(const Options& options);

/// Throws std::invalid_argument for an unknown level name.
void setLevel(std::string_view level);

/// "8f3a…(64 chars)": enough to tell secrets apart in a log, never the secret itself.
std::string redact(std::string_view secret);

}  // namespace hha::logging
