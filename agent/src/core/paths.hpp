#pragma once

#include <filesystem>
#include <string>
#include <string_view>

namespace hha::paths {

/// Where the config lives when --config is not given:
///   Windows  %ProgramData%\HomeHelperAgent\agent.json
///   Linux    /etc/home-helper-agent/agent.json
std::filesystem::path defaultConfigFile();

/// Credentials, the outbox, state and staged updates:
///   Windows  %ProgramData%\HomeHelperAgent\data
///   Linux    /var/lib/home-helper-agent
std::filesystem::path defaultDataDir();

/// The running executable (for the service definition and for updates).
std::filesystem::path currentExecutable();

/// Writes to a temporary file next to `target`, then renames it over the
/// target, so a crash never leaves half a file behind.
void writeFileAtomically(const std::filesystem::path& target, std::string_view contents);

/// The whole file; throws std::runtime_error when it cannot be read.
std::string readFile(const std::filesystem::path& file);

}  // namespace hha::paths
