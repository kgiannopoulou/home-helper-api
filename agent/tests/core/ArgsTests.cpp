#include "core/args.hpp"

#include <catch2/catch_test_macros.hpp>

#include <stdexcept>

using hha::Args;

TEST_CASE("Args: command, options with values, flags and positionals", "[args]") {
    const auto args = Args::parse({"--config", "a.json", "enroll", "--code=ABCD-EFGH", "--verbose", "extra"},
                                  {"config", "code"});
    CHECK(args.command == "enroll");
    CHECK(args.option("config") == "a.json");
    CHECK(args.option("code") == "ABCD-EFGH");
    CHECK(args.flag("verbose"));
    CHECK_FALSE(args.flag("quiet"));
    REQUIRE(args.positional.size() == 1);
    CHECK(args.positional[0] == "extra");
}

TEST_CASE("Args: a value with spaces and one that looks like an option", "[args]") {
    const auto args = Args::parse({"enroll", "--name", "Mini PC", "--", "--not-an-option"}, {"name"});
    CHECK(args.option("name") == "Mini PC");
    REQUIRE(args.positional.size() == 1);
    CHECK(args.positional[0] == "--not-an-option");
}

TEST_CASE("Args: mistakes are errors, not silent flags", "[args]") {
    CHECK_THROWS_AS(Args::parse({"enroll", "--code"}, {"code"}), std::invalid_argument);
    CHECK_THROWS_AS(Args::parse({"run", "--verbose=yes"}, {}), std::invalid_argument);
}
