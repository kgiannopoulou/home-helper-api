#include "core/semver.hpp"

#include <catch2/catch_test_macros.hpp>

using hha::SemVer;

namespace {
SemVer v(const char* text) {
    auto parsed = SemVer::parse(text);
    REQUIRE(parsed.has_value());
    return *parsed;
}
}  // namespace

TEST_CASE("SemVer: parses releases, prereleases, a leading v and build metadata", "[semver]") {
    CHECK(v("1.2.3").toString() == "1.2.3");
    CHECK(v("v0.6.0-rc.1").toString() == "0.6.0-rc.1");
    CHECK(v("1.0.0+build.5").toString() == "1.0.0");
}

TEST_CASE("SemVer: rejects what is not a version", "[semver]") {
    for (const auto* bad : {"", "1", "1.2", "1.2.3.4", "01.2.3", "1.2.x", "1.2.3-", "1.2.3-a..b", "1.2.3-a_b"}) {
        INFO(bad);
        CHECK_FALSE(SemVer::parse(bad).has_value());
    }
}

TEST_CASE("SemVer: precedence follows the spec", "[semver]") {
    // The example chain from semver.org, section 11
    const char* chain[] = {"1.0.0-alpha", "1.0.0-alpha.1", "1.0.0-alpha.beta", "1.0.0-beta",
                           "1.0.0-beta.2", "1.0.0-beta.11", "1.0.0-rc.1", "1.0.0", "1.0.1", "1.1.0", "2.0.0"};
    for (std::size_t i = 0; i + 1 < std::size(chain); ++i) {
        INFO(chain[i] << " < " << chain[i + 1]);
        CHECK(v(chain[i]) < v(chain[i + 1]));
    }
    CHECK(v("1.2.3") == v("v1.2.3+other"));
    CHECK(v("0.10.0") > v("0.9.9"));
}
