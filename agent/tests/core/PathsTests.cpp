#include "core/paths.hpp"

#include "TempDir.hpp"

#include <catch2/catch_test_macros.hpp>

TEST_CASE("paths: atomic write replaces the file and leaves no temporary behind", "[paths]") {
    hha::test::TempDir dir;
    const auto file = dir.path() / "nested" / "state.json";

    hha::paths::writeFileAtomically(file, "first");
    hha::paths::writeFileAtomically(file, "second");

    CHECK(hha::paths::readFile(file) == "second");
    CHECK_FALSE(std::filesystem::exists(file.string() + ".tmp"));
}

TEST_CASE("paths: reading a missing file throws", "[paths]") {
    hha::test::TempDir dir;
    CHECK_THROWS(hha::paths::readFile(dir.path() / "missing"));
}

TEST_CASE("paths: the running executable is a file", "[paths]") {
    CHECK(std::filesystem::is_regular_file(hha::paths::currentExecutable()));
}
