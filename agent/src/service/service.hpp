#pragma once

#include <filesystem>
#include <functional>
#include <stop_token>
#include <string>

/// Running as a background service (docs/service.md).
///
/// Windows: a service in the Service Control Manager, "HomeHelperAgent", running as
/// LocalSystem, started automatically (delayed) and restarted by the SCM if it
/// crashes. Linux: a systemd unit (packaging/linux/home-helper-agent.service);
/// nothing to do in code beyond handling SIGTERM.
namespace hha::service {

inline constexpr const wchar_t* kServiceName = L"HomeHelperAgent";
inline constexpr const char* kDisplayName = "Home Helper Agent";

/// Exit code meaning "removed from the household: don't restart me".
/// systemd: RestartPreventExitStatus=3. Windows: reported as a clean stop.
inline constexpr int kExitRevoked = 3;

/// Exit code meaning "a verified update is in place (or staged): start me again".
/// Counts as a failure for the SCM and systemd, so both restart it.
inline constexpr int kExitRestartForUpdate = 75;

/// The agent's work: runs until the token is stopped, returns an exit code.
using Body = std::function<int(std::stop_token)>;

#ifdef _WIN32
/// Hands the process to the SCM (called for `run --service`); returns when the
/// service stops. False when the process wasn't started by the SCM.
bool runAsWindowsService(Body body);

struct InstallOptions {
    std::filesystem::path executable;
    std::filesystem::path configFile;
    std::filesystem::path dataDir;
    /// Lock the config and data directories down to SYSTEM and Administrators.
    /// Only for the default locations under %ProgramData%: a custom folder in
    /// someone's profile would lock them out of it.
    bool restrictDirectories = true;
};

/// Creates (or updates) the service, its recovery actions and description, and
/// locks the data directory down to SYSTEM and Administrators. Needs an
/// elevated prompt. Throws std::runtime_error with what went wrong.
void install(const InstallOptions& options);
void uninstall();
void start();
/// Asks the service to stop and waits up to 30 s
void stop();
/// "running", "stopped", "start pending"… or "not installed"
std::string status();
#endif

}  // namespace hha::service
