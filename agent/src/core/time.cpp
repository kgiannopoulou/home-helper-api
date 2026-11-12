#include "core/time.hpp"

#include <charconv>
#include <cstdio>
#include <ctime>

namespace hha::time {

namespace {

// Days since 1970-01-01 for a civil date (Howard Hinnant's algorithm): no time zone database needed
std::int64_t daysFromCivil(std::int64_t y, unsigned m, unsigned d) {
    y -= m <= 2;
    const std::int64_t era = (y >= 0 ? y : y - 399) / 400;
    const auto yoe = static_cast<unsigned>(y - era * 400);
    const unsigned doy = (153 * (m + (m > 2 ? -3 : 9)) + 2) / 5 + d - 1;
    const unsigned doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    return era * 146097 + static_cast<std::int64_t>(doe) - 719468;
}

bool readInt(std::string_view text, std::size_t pos, std::size_t len, int& out) {
    if (pos + len > text.size()) return false;
    const auto* first = text.data() + pos;
    const auto [ptr, ec] = std::from_chars(first, first + len, out);
    return ec == std::errc{} && ptr == first + len;
}

}  // namespace

std::string iso8601(SystemClock::time_point when) {
    const auto ms = std::chrono::duration_cast<std::chrono::milliseconds>(when.time_since_epoch()).count();
    auto seconds = static_cast<std::time_t>(ms / 1000);
    auto millis = static_cast<int>(ms % 1000);
    if (millis < 0) {
        millis += 1000;
        --seconds;
    }
    std::tm tm{};
#ifdef _WIN32
    gmtime_s(&tm, &seconds);
#else
    gmtime_r(&seconds, &tm);
#endif
    char buffer[32];
    std::snprintf(buffer, sizeof(buffer), "%04d-%02d-%02dT%02d:%02d:%02d.%03dZ", tm.tm_year + 1900, tm.tm_mon + 1,
                  tm.tm_mday, tm.tm_hour, tm.tm_min, tm.tm_sec, millis);
    return buffer;
}

std::string nowIso8601() {
    return iso8601(SystemClock::now());
}

std::optional<SystemClock::time_point> parseIso8601(std::string_view text) {
    int year = 0, month = 0, day = 0, hour = 0, minute = 0, second = 0;
    if (text.size() < 20 || text[4] != '-' || text[7] != '-' || (text[10] != 'T' && text[10] != ' ') ||
        text[13] != ':' || text[16] != ':' || !readInt(text, 0, 4, year) || !readInt(text, 5, 2, month) ||
        !readInt(text, 8, 2, day) || !readInt(text, 11, 2, hour) || !readInt(text, 14, 2, minute) ||
        !readInt(text, 17, 2, second)) {
        return std::nullopt;
    }
    if (month < 1 || month > 12 || day < 1 || day > 31 || hour > 23 || minute > 59 || second > 60) return std::nullopt;

    std::size_t pos = 19;
    long long millis = 0;
    if (pos < text.size() && text[pos] == '.') {
        ++pos;
        int digits = 0;
        while (pos < text.size() && text[pos] >= '0' && text[pos] <= '9') {
            if (digits < 3) millis = millis * 10 + (text[pos] - '0');
            ++digits;
            ++pos;
        }
        if (digits == 0) return std::nullopt;
        for (int i = digits; i < 3; ++i) millis *= 10;
    }
    const auto zone = text.substr(pos);
    if (zone != "Z" && zone != "+00:00" && zone != "z") return std::nullopt;

    const auto days = daysFromCivil(year, static_cast<unsigned>(month), static_cast<unsigned>(day));
    const auto secs = days * 86400 + hour * 3600 + minute * 60 + second;
    return SystemClock::time_point(std::chrono::duration_cast<SystemClock::duration>(
        std::chrono::seconds(secs) + std::chrono::milliseconds(millis)));
}

std::int64_t unixSeconds(SystemClock::time_point when) {
    return std::chrono::duration_cast<std::chrono::seconds>(when.time_since_epoch()).count();
}

std::string humanDuration(std::chrono::seconds duration) {
    auto s = duration.count();
    if (s < 0) s = 0;
    if (s < 60) return std::to_string(s) + " s";
    if (s < 3600) return std::to_string(s / 60) + " min";
    if (s < 86400) return std::to_string(s / 3600) + " h " + std::to_string((s % 3600) / 60) + " min";
    return std::to_string(s / 86400) + " d " + std::to_string((s % 86400) / 3600) + " h";
}

}  // namespace hha::time
