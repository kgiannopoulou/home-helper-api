#pragma once

#include <chrono>
#include <cstdint>
#include <optional>
#include <string>
#include <string_view>

namespace hha::time {

using SystemClock = std::chrono::system_clock;

/// "2026-11-12T10:00:00.123Z" (UTC, milliseconds)
std::string iso8601(SystemClock::time_point when);
std::string nowIso8601();

/// Parses what the server sends: "2026-11-12T10:00:00Z", with optional
/// fractional seconds and a Z or +00:00 offset. nullopt for anything else.
std::optional<SystemClock::time_point> parseIso8601(std::string_view text);

std::int64_t unixSeconds(SystemClock::time_point when = SystemClock::now());

/// "3 h 4 min", "45 s": for logs and the CLI
std::string humanDuration(std::chrono::seconds duration);

}  // namespace hha::time
