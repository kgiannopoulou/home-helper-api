#include "commands/process.hpp"

#include "core/strings.hpp"

#include <stdexcept>
#include <thread>

#ifdef _WIN32
#include <windows.h>
#else
#include <cerrno>
#include <csignal>
#include <cstring>
#include <fcntl.h>
#include <poll.h>
#include <spawn.h>
#include <sys/wait.h>
#include <unistd.h>
extern char** environ;
#endif

namespace hha::commands {

std::wstring quoteWindowsArgument(const std::wstring& arg) {
    // The rules of CommandLineToArgvW: backslashes are literal unless they come
    // before a quote; then each pair is one backslash, and an odd one escapes the quote.
    if (!arg.empty() && arg.find_first_of(L" \t\n\v\"") == std::wstring::npos) return arg;
    std::wstring out = L"\"";
    std::size_t backslashes = 0;
    for (const wchar_t c : arg) {
        if (c == L'\\') {
            ++backslashes;
        } else if (c == L'"') {
            out.append(backslashes * 2 + 1, L'\\');
            out.push_back(L'"');
            backslashes = 0;
        } else {
            out.append(backslashes, L'\\');
            out.push_back(c);
            backslashes = 0;
        }
    }
    out.append(backslashes * 2, L'\\');  // before the closing quote
    out.push_back(L'"');
    return out;
}

namespace {

void append(ProcessResult& result, const char* data, std::size_t size, std::size_t limit) {
    if (result.output.size() >= limit) {
        result.truncated = result.truncated || size > 0;
        return;
    }
    const auto room = limit - result.output.size();
    result.output.append(data, std::min(size, room));
    if (size > room) result.truncated = true;
}

}  // namespace

#ifdef _WIN32

namespace {

struct Handle {
    HANDLE h = nullptr;
    ~Handle() { reset(); }
    void reset() {
        if (h && h != INVALID_HANDLE_VALUE) CloseHandle(h);
        h = nullptr;
    }
};

[[noreturn]] void fail(const std::string& what) {
    throw std::runtime_error(what + " failed: error " + std::to_string(GetLastError()));
}

}  // namespace

ProcessResult runProcess(const std::filesystem::path& program, const std::vector<std::string>& args,
                         std::chrono::milliseconds timeout, std::size_t maxOutputBytes) {
    const auto started = std::chrono::steady_clock::now();
    ProcessResult result;

    SECURITY_ATTRIBUTES inherit{sizeof(SECURITY_ATTRIBUTES), nullptr, TRUE};
    Handle readEnd, writeEnd, nul;
    if (!CreatePipe(&readEnd.h, &writeEnd.h, &inherit, 0)) fail("CreatePipe");
    SetHandleInformation(readEnd.h, HANDLE_FLAG_INHERIT, 0);
    nul.h = CreateFileW(L"NUL", GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, &inherit, OPEN_EXISTING, 0, nullptr);

    // Only these two handles reach the child
    HANDLE inherited[] = {writeEnd.h, nul.h};
    SIZE_T attrSize = 0;
    InitializeProcThreadAttributeList(nullptr, 1, 0, &attrSize);
    std::vector<char> attrBuffer(attrSize);
    auto* attrs = reinterpret_cast<LPPROC_THREAD_ATTRIBUTE_LIST>(attrBuffer.data());
    if (!InitializeProcThreadAttributeList(attrs, 1, 0, &attrSize)) fail("InitializeProcThreadAttributeList");
    UpdateProcThreadAttribute(attrs, 0, PROC_THREAD_ATTRIBUTE_HANDLE_LIST, inherited, sizeof(inherited), nullptr, nullptr);

    STARTUPINFOEXW si{};
    si.StartupInfo.cb = sizeof(si);
    si.StartupInfo.dwFlags = STARTF_USESTDHANDLES;
    si.StartupInfo.hStdInput = nul.h;
    si.StartupInfo.hStdOutput = writeEnd.h;
    si.StartupInfo.hStdError = writeEnd.h;
    si.lpAttributeList = attrs;

    std::wstring commandLine = quoteWindowsArgument(program.wstring());
    for (const auto& a : args) commandLine += L" " + quoteWindowsArgument(strings::widen(a));

    // A job, so a timeout also ends whatever the program started
    Handle job;
    job.h = CreateJobObjectW(nullptr, nullptr);
    JOBOBJECT_EXTENDED_LIMIT_INFORMATION limits{};
    limits.BasicLimitInformation.LimitFlags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
    SetInformationJobObject(job.h, JobObjectExtendedLimitInformation, &limits, sizeof(limits));

    PROCESS_INFORMATION pi{};
    // lpApplicationName is the exact program: no search of PATH or the current directory
    const BOOL ok = CreateProcessW(program.c_str(), commandLine.data(), nullptr, nullptr, TRUE,
                                   CREATE_NO_WINDOW | CREATE_SUSPENDED | EXTENDED_STARTUPINFO_PRESENT |
                                       CREATE_UNICODE_ENVIRONMENT,
                                   nullptr, nullptr, &si.StartupInfo, &pi);
    DeleteProcThreadAttributeList(attrs);
    if (!ok) fail("starting " + program.string());
    Handle process, thread;
    process.h = pi.hProcess;
    thread.h = pi.hThread;
    AssignProcessToJobObject(job.h, process.h);
    ResumeThread(thread.h);
    writeEnd.reset();  // the child has its copy; EOF arrives when it (and its children) exit

    std::thread reader([&] {
        char buffer[4096];
        DWORD n = 0;
        while (ReadFile(readEnd.h, buffer, sizeof(buffer), &n, nullptr) && n > 0) append(result, buffer, n, maxOutputBytes);
    });

    if (WaitForSingleObject(process.h, static_cast<DWORD>(timeout.count())) == WAIT_TIMEOUT) {
        result.timedOut = true;
        TerminateJobObject(job.h, 1);
        WaitForSingleObject(process.h, 5000);
    }
    DWORD code = 0;
    GetExitCodeProcess(process.h, &code);
    result.exitCode = static_cast<int>(code);
    // Grandchildren may still hold the pipe open: end them with the job, then the read ends
    TerminateJobObject(job.h, 1);
    reader.join();
    result.duration = std::chrono::duration_cast<std::chrono::milliseconds>(std::chrono::steady_clock::now() - started);
    return result;
}

#else

ProcessResult runProcess(const std::filesystem::path& program, const std::vector<std::string>& args,
                         std::chrono::milliseconds timeout, std::size_t maxOutputBytes) {
    const auto started = std::chrono::steady_clock::now();
    ProcessResult result;

    int fds[2];
    if (pipe2(fds, O_CLOEXEC) != 0) throw std::runtime_error(std::string("pipe: ") + std::strerror(errno));

    posix_spawn_file_actions_t actions;
    posix_spawn_file_actions_init(&actions);
    posix_spawn_file_actions_addopen(&actions, STDIN_FILENO, "/dev/null", O_RDONLY, 0);
    posix_spawn_file_actions_adddup2(&actions, fds[1], STDOUT_FILENO);
    posix_spawn_file_actions_adddup2(&actions, fds[1], STDERR_FILENO);

    posix_spawnattr_t attr;
    posix_spawnattr_init(&attr);
    // Its own process group, so a timeout kills everything it started
    posix_spawnattr_setpgroup(&attr, 0);
    sigset_t defaults;
    sigemptyset(&defaults);
    sigaddset(&defaults, SIGINT);
    sigaddset(&defaults, SIGTERM);
    sigaddset(&defaults, SIGPIPE);
    posix_spawnattr_setsigdefault(&attr, &defaults);
    sigset_t none;
    sigemptyset(&none);
    posix_spawnattr_setsigmask(&attr, &none);  // the agent blocks SIGINT/SIGTERM; the child must not inherit that
    posix_spawnattr_setflags(&attr, POSIX_SPAWN_SETPGROUP | POSIX_SPAWN_SETSIGDEF | POSIX_SPAWN_SETSIGMASK);

    std::vector<std::string> argvStrings;
    argvStrings.push_back(program.string());
    argvStrings.insert(argvStrings.end(), args.begin(), args.end());
    std::vector<char*> argv;
    for (auto& a : argvStrings) argv.push_back(a.data());
    argv.push_back(nullptr);

    pid_t pid = 0;
    // posix_spawn, not posix_spawnp: the exact path, no PATH search
    const int rc = posix_spawn(&pid, program.c_str(), &actions, &attr, argv.data(), environ);
    posix_spawn_file_actions_destroy(&actions);
    posix_spawnattr_destroy(&attr);
    close(fds[1]);
    if (rc != 0) {
        close(fds[0]);
        throw std::runtime_error("starting " + program.string() + ": " + std::strerror(rc));
    }

    const auto deadline = started + timeout;
    char buffer[4096];
    bool open = true;
    while (open) {
        const auto left = std::chrono::duration_cast<std::chrono::milliseconds>(deadline - std::chrono::steady_clock::now());
        if (left.count() <= 0) {
            result.timedOut = true;
            kill(-pid, SIGKILL);
            break;
        }
        pollfd p{fds[0], POLLIN, 0};
        const int ready = poll(&p, 1, static_cast<int>(std::min<long long>(left.count(), 500)));
        if (ready < 0 && errno != EINTR) break;
        if (ready > 0) {
            const auto n = read(fds[0], buffer, sizeof(buffer));
            if (n > 0) {
                append(result, buffer, static_cast<std::size_t>(n), maxOutputBytes);
            } else if (n == 0 || errno != EINTR) {
                open = false;
            }
        }
    }
    close(fds[0]);

    int status = 0;
    while (waitpid(pid, &status, 0) < 0 && errno == EINTR) {
    }
    if (!result.timedOut) kill(-pid, SIGKILL);  // anything it left running in its group
    if (WIFEXITED(status)) {
        result.exitCode = WEXITSTATUS(status);
    } else if (WIFSIGNALED(status)) {
        result.exitCode = 128 + WTERMSIG(status);
    }
    result.duration = std::chrono::duration_cast<std::chrono::milliseconds>(std::chrono::steady_clock::now() - started);
    return result;
}

#endif

}  // namespace hha::commands
