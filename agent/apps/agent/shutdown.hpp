#pragma once

#include <functional>

namespace hha::cli {

/// Calls `stop` once on Ctrl+C / Ctrl+Break / console close (Windows) or
/// SIGINT / SIGTERM (Linux, from a sigwait thread, so `stop` may lock and log).
/// Call once, early, before other threads start (on Linux the signals are
/// blocked in every thread created afterwards).
void onShutdownRequest(std::function<void()> stop);

}  // namespace hha::cli
