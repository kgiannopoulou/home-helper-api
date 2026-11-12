#include "shutdown.hpp"

#include <mutex>
#include <thread>

#ifdef _WIN32
#include <windows.h>
#else
#include <csignal>
#include <pthread.h>
#endif

namespace hha::cli {

namespace {
std::function<void()> gStop;
std::once_flag gOnce;

void fire() {
    std::call_once(gOnce, [] {
        if (gStop) gStop();
    });
}

#ifdef _WIN32
BOOL WINAPI consoleHandler(DWORD type) {
    switch (type) {
        case CTRL_C_EVENT:
        case CTRL_BREAK_EVENT:
        case CTRL_CLOSE_EVENT:
        case CTRL_SHUTDOWN_EVENT:
            fire();
            return TRUE;
        default:
            return FALSE;
    }
}
#endif
}  // namespace

void onShutdownRequest(std::function<void()> stop) {
    gStop = std::move(stop);
#ifdef _WIN32
    SetConsoleCtrlHandler(consoleHandler, TRUE);
#else
    sigset_t set;
    sigemptyset(&set);
    sigaddset(&set, SIGINT);
    sigaddset(&set, SIGTERM);
    pthread_sigmask(SIG_BLOCK, &set, nullptr);
    std::thread([set] {
        int sig = 0;
        sigwait(&set, &sig);
        fire();
    }).detach();
#endif
}

}  // namespace hha::cli
