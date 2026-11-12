// The Windows service: SCM registration, recovery actions, and the service
// main that runs the agent until the SCM says stop.
#include "service/service.hpp"

#include "core/strings.hpp"

#include <spdlog/spdlog.h>

#include <windows.h>
#include <aclapi.h>
#include <sddl.h>

#include <stdexcept>
#include <thread>

namespace hha::service {

namespace {

struct ScHandle {
    SC_HANDLE h = nullptr;
    explicit ScHandle(SC_HANDLE handle) : h(handle) {}
    ~ScHandle() {
        if (h) CloseServiceHandle(h);
    }
    explicit operator bool() const { return h != nullptr; }
};

[[noreturn]] void fail(const std::string& what) {
    const DWORD code = GetLastError();
    std::string hint;
    if (code == ERROR_ACCESS_DENIED) hint = " (run it from an elevated prompt: Run as administrator)";
    if (code == ERROR_SERVICE_DOES_NOT_EXIST) hint = " (the service is not installed)";
    if (code == ERROR_SERVICE_EXISTS) hint = " (already installed)";
    throw std::runtime_error(what + " failed: error " + std::to_string(code) + hint);
}

std::wstring quoted(const std::filesystem::path& p) {
    return L"\"" + p.wstring() + L"\"";
}

// --- The running service ------------------------------------------------------

SERVICE_STATUS_HANDLE gStatusHandle = nullptr;
SERVICE_STATUS gStatus{};
std::stop_source* gStop = nullptr;
Body gBody;

void report(DWORD state, DWORD exitCode = NO_ERROR, DWORD waitHint = 0) {
    gStatus.dwServiceType = SERVICE_WIN32_OWN_PROCESS;
    gStatus.dwCurrentState = state;
    gStatus.dwWin32ExitCode = exitCode;
    gStatus.dwWaitHint = waitHint;
    gStatus.dwControlsAccepted =
        state == SERVICE_RUNNING ? SERVICE_ACCEPT_STOP | SERVICE_ACCEPT_SHUTDOWN | SERVICE_ACCEPT_PRESHUTDOWN : 0;
    static DWORD checkpoint = 1;
    gStatus.dwCheckPoint = (state == SERVICE_RUNNING || state == SERVICE_STOPPED) ? 0 : checkpoint++;
    SetServiceStatus(gStatusHandle, &gStatus);
}

DWORD WINAPI controlHandler(DWORD control, DWORD, LPVOID, LPVOID) {
    switch (control) {
        case SERVICE_CONTROL_STOP:
        case SERVICE_CONTROL_SHUTDOWN:
        case SERVICE_CONTROL_PRESHUTDOWN:
            report(SERVICE_STOP_PENDING, NO_ERROR, 15000);
            if (gStop) gStop->request_stop();
            return NO_ERROR;
        case SERVICE_CONTROL_INTERROGATE:
            return NO_ERROR;
        default:
            return ERROR_CALL_NOT_IMPLEMENTED;
    }
}

void WINAPI serviceMain(DWORD, LPWSTR*) {
    gStatusHandle = RegisterServiceCtrlHandlerExW(kServiceName, controlHandler, nullptr);
    if (!gStatusHandle) return;
    report(SERVICE_START_PENDING, NO_ERROR, 10000);

    std::stop_source stop;
    gStop = &stop;
    report(SERVICE_RUNNING);
    int code = 1;
    try {
        code = gBody(stop.get_token());
    } catch (const std::exception& e) {
        spdlog::critical("The agent stopped with an error: {}", e.what());
        code = 1;
    }
    gStop = nullptr;
    spdlog::default_logger()->flush();
    // Revoked is a clean stop, so the recovery actions don't restart it; anything
    // else that ended the agent without a stop request is a failure, and they do
    const bool failed = code != 0 && code != kExitRevoked && !stop.stop_requested();
    report(SERVICE_STOPPED, failed ? ERROR_SERVICE_SPECIFIC_ERROR : NO_ERROR);
}

/// Inherited ACL on the data directory: full control for SYSTEM and Administrators only
void lockDown(const std::filesystem::path& dir) {
    std::filesystem::create_directories(dir);
    PSECURITY_DESCRIPTOR sd = nullptr;
    if (!ConvertStringSecurityDescriptorToSecurityDescriptorW(L"D:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)", SDDL_REVISION_1,
                                                              &sd, nullptr)) {
        fail("building the data directory ACL");
    }
    BOOL present = FALSE, defaulted = FALSE;
    PACL dacl = nullptr;
    GetSecurityDescriptorDacl(sd, &present, &dacl, &defaulted);
    const auto rc = SetNamedSecurityInfoW(const_cast<wchar_t*>(dir.c_str()), SE_FILE_OBJECT,
                                          DACL_SECURITY_INFORMATION | PROTECTED_DACL_SECURITY_INFORMATION, nullptr,
                                          nullptr, dacl, nullptr);
    LocalFree(sd);
    if (rc != ERROR_SUCCESS) {
        SetLastError(rc);
        fail("restricting access to " + dir.string());
    }
}

}  // namespace

bool runAsWindowsService(Body body) {
    gBody = std::move(body);
    SERVICE_TABLE_ENTRYW table[] = {{const_cast<LPWSTR>(kServiceName), serviceMain}, {nullptr, nullptr}};
    if (!StartServiceCtrlDispatcherW(table)) {
        return GetLastError() != ERROR_FAILED_SERVICE_CONTROLLER_CONNECT ? (fail("StartServiceCtrlDispatcher"), false)
                                                                          : false;
    }
    return true;
}

void install(const InstallOptions& o) {
    ScHandle scm(OpenSCManagerW(nullptr, nullptr, SC_MANAGER_CREATE_SERVICE | SC_MANAGER_CONNECT));
    if (!scm) fail("opening the Service Control Manager");

    const std::wstring command =
        quoted(o.executable) + L" --config " + quoted(o.configFile) + L" run --service";
    const auto display = strings::widen(kDisplayName);

    ScHandle svc(OpenServiceW(scm.h, kServiceName, SERVICE_ALL_ACCESS));
    if (svc) {
        if (!ChangeServiceConfigW(svc.h, SERVICE_WIN32_OWN_PROCESS, SERVICE_AUTO_START, SERVICE_ERROR_NORMAL,
                                  command.c_str(), nullptr, nullptr, nullptr, nullptr, nullptr, display.c_str())) {
            fail("updating the service");
        }
    } else {
        svc.h = CreateServiceW(scm.h, kServiceName, display.c_str(), SERVICE_ALL_ACCESS, SERVICE_WIN32_OWN_PROCESS,
                               SERVICE_AUTO_START, SERVICE_ERROR_NORMAL, command.c_str(), nullptr, nullptr,
                               nullptr, nullptr /* LocalSystem */, nullptr);
        if (!svc) fail("creating the service");
    }

    std::wstring text = L"Connects this computer to Home Helper: status, events and allowlisted commands.";
    SERVICE_DESCRIPTIONW description{text.data()};
    ChangeServiceConfig2W(svc.h, SERVICE_CONFIG_DESCRIPTION, &description);

    // After boot, once the network is up
    SERVICE_DELAYED_AUTO_START_INFO delayed{TRUE};
    ChangeServiceConfig2W(svc.h, SERVICE_CONFIG_DELAYED_AUTO_START_INFO, &delayed);

    // Crashed or failed: restart after 10 s, then 60 s, then every 5 min; forget failures after a day
    SC_ACTION actions[] = {{SC_ACTION_RESTART, 10'000}, {SC_ACTION_RESTART, 60'000}, {SC_ACTION_RESTART, 300'000}};
    SERVICE_FAILURE_ACTIONSW failure{};
    failure.dwResetPeriod = 24 * 3600;
    failure.cActions = 3;
    failure.lpsaActions = actions;
    if (!ChangeServiceConfig2W(svc.h, SERVICE_CONFIG_FAILURE_ACTIONS, &failure)) fail("setting the recovery actions");
    // A stop with an error code counts as a failure too, not only a crash
    SERVICE_FAILURE_ACTIONS_FLAG flag{TRUE};
    ChangeServiceConfig2W(svc.h, SERVICE_CONFIG_FAILURE_ACTIONS_FLAG, &flag);

    // Only the privileges it uses: reading the system, starting the allowlisted programs, shutting down
    std::wstring privileges = std::wstring(SE_SHUTDOWN_NAME) + L'\0' + SE_CHANGE_NOTIFY_NAME + L'\0' +
                              SE_INCREASE_QUOTA_NAME + L'\0' + SE_ASSIGNPRIMARYTOKEN_NAME + L'\0' + L'\0';
    SERVICE_REQUIRED_PRIVILEGES_INFOW required{privileges.data()};
    ChangeServiceConfig2W(svc.h, SERVICE_CONFIG_REQUIRED_PRIVILEGES_INFO, &required);

    if (o.restrictDirectories) {
        lockDown(o.dataDir);
        if (o.configFile.has_parent_path()) lockDown(o.configFile.parent_path());
    }
}

void uninstall() {
    ScHandle scm(OpenSCManagerW(nullptr, nullptr, SC_MANAGER_CONNECT));
    if (!scm) fail("opening the Service Control Manager");
    ScHandle svc(OpenServiceW(scm.h, kServiceName, SERVICE_STOP | SERVICE_QUERY_STATUS | DELETE));
    if (!svc) fail("opening the service");
    SERVICE_STATUS s{};
    ControlService(svc.h, SERVICE_CONTROL_STOP, &s);
    if (!DeleteService(svc.h)) fail("deleting the service");
}

void start() {
    ScHandle scm(OpenSCManagerW(nullptr, nullptr, SC_MANAGER_CONNECT));
    if (!scm) fail("opening the Service Control Manager");
    ScHandle svc(OpenServiceW(scm.h, kServiceName, SERVICE_START));
    if (!svc) fail("opening the service");
    if (!StartServiceW(svc.h, 0, nullptr) && GetLastError() != ERROR_SERVICE_ALREADY_RUNNING) fail("starting the service");
}

void stop() {
    ScHandle scm(OpenSCManagerW(nullptr, nullptr, SC_MANAGER_CONNECT));
    if (!scm) fail("opening the Service Control Manager");
    ScHandle svc(OpenServiceW(scm.h, kServiceName, SERVICE_STOP | SERVICE_QUERY_STATUS));
    if (!svc) fail("opening the service");
    SERVICE_STATUS s{};
    if (!ControlService(svc.h, SERVICE_CONTROL_STOP, &s) && GetLastError() != ERROR_SERVICE_NOT_ACTIVE) {
        fail("stopping the service");
    }
    for (int i = 0; i < 60 && QueryServiceStatus(svc.h, &s) && s.dwCurrentState != SERVICE_STOPPED; ++i) {
        std::this_thread::sleep_for(std::chrono::milliseconds(500));
    }
}

std::string status() {
    ScHandle scm(OpenSCManagerW(nullptr, nullptr, SC_MANAGER_CONNECT));
    if (!scm) return "unknown";
    ScHandle svc(OpenServiceW(scm.h, kServiceName, SERVICE_QUERY_STATUS));
    if (!svc) return "not installed";
    SERVICE_STATUS s{};
    if (!QueryServiceStatus(svc.h, &s)) return "unknown";
    switch (s.dwCurrentState) {
        case SERVICE_RUNNING: return "running";
        case SERVICE_STOPPED: return "stopped";
        case SERVICE_START_PENDING: return "start pending";
        case SERVICE_STOP_PENDING: return "stop pending";
        case SERVICE_PAUSED: return "paused";
        default: return "unknown";
    }
}

}  // namespace hha::service
