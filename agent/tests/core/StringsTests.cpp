#include "core/logging.hpp"
#include "core/strings.hpp"

#include <catch2/catch_test_macros.hpp>

namespace strings = hha::strings;

TEST_CASE("strings: trim, split, lower, prefix", "[strings]") {
    CHECK(strings::trim("  a b\t\r\n") == "a b");
    CHECK(strings::trim("") == "");
    const auto parts = strings::split("a..b", '.');
    REQUIRE(parts.size() == 3);
    CHECK(parts[1].empty());
    CHECK(strings::toLower("X-HH-Signature") == "x-hh-signature");
    CHECK(strings::startsWith("network.ping", "network."));
    CHECK_FALSE(strings::startsWith("net", "network."));
}

TEST_CASE("strings: printable text has no control characters", "[strings]") {
    CHECK(strings::isPrintable("192.168.1.1"));
    CHECK(strings::isPrintable("Κουζίνα"));  // UTF-8 is fine
    CHECK_FALSE(strings::isPrintable("a\nb"));
    CHECK_FALSE(strings::isPrintable(std::string("a\0b", 3)));
}

#ifdef _WIN32
TEST_CASE("strings: UTF-8 and UTF-16 round trip", "[strings]") {
    const std::string greek = "Σαλόνι mini PC";
    CHECK(strings::narrow(strings::widen(greek)) == greek);
}
#endif

TEST_CASE("logging: redact never shows the whole secret", "[logging]") {
    const std::string secret(64, 'f');
    const auto shown = hha::logging::redact(secret);
    CHECK(shown.find(secret) == std::string::npos);
    CHECK(shown.find("64 chars") != std::string::npos);
    CHECK(hha::logging::redact("short").find("short") == std::string::npos);
    CHECK(hha::logging::redact("") == "(empty)");
}
