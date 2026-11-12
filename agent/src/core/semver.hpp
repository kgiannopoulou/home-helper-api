#pragma once

#include <compare>
#include <optional>
#include <string>
#include <string_view>

namespace hha {

/// MAJOR.MINOR.PATCH with an optional -prerelease ("0.6.0-rc.1"). Build
/// metadata (+…) is accepted and ignored, as SemVer says.
struct SemVer {
    unsigned major = 0;
    unsigned minor = 0;
    unsigned patch = 0;
    std::string prerelease;

    static std::optional<SemVer> parse(std::string_view text);

    std::string toString() const;

    /// SemVer precedence: 1.0.0-rc.1 < 1.0.0; prerelease parts compare
    /// numerically when both are numbers, otherwise as text.
    std::strong_ordering operator<=>(const SemVer& other) const;
    bool operator==(const SemVer& other) const { return (*this <=> other) == 0; }
};

}  // namespace hha
