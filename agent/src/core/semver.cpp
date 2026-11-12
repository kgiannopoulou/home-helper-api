#include "core/semver.hpp"

#include "core/strings.hpp"

#include <charconv>
#include <vector>

namespace hha {

namespace {

std::optional<unsigned> parseNumber(std::string_view part) {
    if (part.empty() || (part.size() > 1 && part.front() == '0')) {
        return std::nullopt;  // SemVer forbids leading zeros
    }
    unsigned value = 0;
    const auto* end = part.data() + part.size();
    const auto [ptr, ec] = std::from_chars(part.data(), end, value);
    if (ec != std::errc{} || ptr != end) {
        return std::nullopt;
    }
    return value;
}

bool isIdentifier(std::string_view part) {
    if (part.empty()) {
        return false;
    }
    for (const char c : part) {
        const bool ok = (c >= '0' && c <= '9') || (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || c == '-';
        if (!ok) {
            return false;
        }
    }
    return true;
}

}  // namespace

std::optional<SemVer> SemVer::parse(std::string_view text) {
    text = strings::trim(text);
    if (!text.empty() && text.front() == 'v') {
        text.remove_prefix(1);
    }
    if (const auto plus = text.find('+'); plus != std::string_view::npos) {
        text = text.substr(0, plus);
    }

    std::string_view core = text;
    std::string_view pre;
    if (const auto dash = text.find('-'); dash != std::string_view::npos) {
        core = text.substr(0, dash);
        pre = text.substr(dash + 1);
        if (pre.empty()) {
            return std::nullopt;
        }
        for (const auto& id : strings::split(pre, '.')) {
            if (!isIdentifier(id)) {
                return std::nullopt;
            }
        }
    }

    const auto parts = strings::split(core, '.');
    if (parts.size() != 3) {
        return std::nullopt;
    }
    const auto major = parseNumber(parts[0]);
    const auto minor = parseNumber(parts[1]);
    const auto patch = parseNumber(parts[2]);
    if (!major || !minor || !patch) {
        return std::nullopt;
    }
    return SemVer{*major, *minor, *patch, std::string(pre)};
}

std::string SemVer::toString() const {
    auto text = std::to_string(major) + "." + std::to_string(minor) + "." + std::to_string(patch);
    if (!prerelease.empty()) {
        text += "-" + prerelease;
    }
    return text;
}

std::strong_ordering SemVer::operator<=>(const SemVer& other) const {
    if (const auto c = major <=> other.major; c != 0) return c;
    if (const auto c = minor <=> other.minor; c != 0) return c;
    if (const auto c = patch <=> other.patch; c != 0) return c;

    // A version without a prerelease ranks above the same version with one
    if (prerelease.empty() || other.prerelease.empty()) {
        if (prerelease.empty() && other.prerelease.empty()) return std::strong_ordering::equal;
        return prerelease.empty() ? std::strong_ordering::greater : std::strong_ordering::less;
    }

    const auto mine = strings::split(prerelease, '.');
    const auto theirs = strings::split(other.prerelease, '.');
    for (std::size_t i = 0; i < mine.size() && i < theirs.size(); ++i) {
        const auto a = parseNumber(mine[i]);
        const auto b = parseNumber(theirs[i]);
        if (a && b) {
            if (const auto c = *a <=> *b; c != 0) return c;
        } else if (a || b) {
            return a ? std::strong_ordering::less : std::strong_ordering::greater;  // numbers rank lower
        } else if (const auto c = mine[i].compare(theirs[i]); c != 0) {
            return c < 0 ? std::strong_ordering::less : std::strong_ordering::greater;
        }
    }
    return mine.size() <=> theirs.size();
}

}  // namespace hha
