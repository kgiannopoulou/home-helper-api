#include "agent/config.hpp"

#include "TempDir.hpp"
#include "core/paths.hpp"

#include <catch2/catch_test_macros.hpp>
#include <catch2/matchers/catch_matchers_string.hpp>

using namespace hha;
using nlohmann::json;
using Catch::Matchers::ContainsSubstring;

TEST_CASE("config: a missing file means defaults") {
    test::TempDir dir;
    const auto c = agent::Config::load(dir.path() / "agent.json");
    CHECK(c.heartbeat == std::chrono::seconds(30));
    CHECK(c.thresholds.cpuHighPercent == 90);
    CHECK(c.commands.empty());
    CHECK_FALSE(c.updatePublicKey.has_value());
    CHECK(c.credentialsFile().filename() == "credentials.json");
}

TEST_CASE("config: reads every setting") {
    const auto c = agent::Config::fromJson(json::parse(R"({
        "server_url": "https://home.example.org", "name": "Mini PC", "data_dir": "D:/hh",
        "log": {"level": "debug", "file": false}, "heartbeat_seconds": 15, "inventory_hours": 2,
        "monitor": {"cpu_high_percent": 80, "disk_low_free_percent": 5, "sustain_samples": 2, "disks": ["C:\\"]},
        "audio": {"enabled": false}, "outbox": {"max_events": 50},
        "commands": [{"key": "x"}],
        "updates": {"public_key": "d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a"}
    })"));
    CHECK(c.serverUrl == "https://home.example.org");
    CHECK(c.name == "Mini PC");
    CHECK(c.dataDir == std::filesystem::path("D:/hh"));
    CHECK(c.logLevel == "debug");
    CHECK_FALSE(c.logToFile);
    CHECK(c.heartbeat == std::chrono::seconds(15));
    CHECK(c.inventoryEvery == std::chrono::hours(2));
    CHECK(c.thresholds.cpuHighPercent == 80);
    CHECK(c.thresholds.diskLowFreePercent == 5);
    CHECK(c.thresholds.sustainSamples == 2);
    CHECK(c.disks == std::vector<std::string>{"C:\\"});
    CHECK_FALSE(c.audioDiscovery);
    CHECK(c.outboxMaxEvents == 50);
    CHECK(c.commands.size() == 1);
    CHECK(c.updatePublicKey.has_value());
}

TEST_CASE("config: lists every problem at once") {
    try {
        agent::Config::fromJson(json::parse(R"({
            "sever_url": "typo", "heartbeat_seconds": 1, "log": {"level": "loud"},
            "monitor": {"cpu_high_percent": "high"}, "commands": {}, "updates": {"public_key": "abc"}
        })"));
        FAIL("expected ConfigError");
    } catch (const agent::ConfigError& e) {
        CHECK(e.problems().size() == 6);
        CHECK_THAT(e.what(), ContainsSubstring("sever_url: unknown setting"));
        CHECK_THAT(e.what(), ContainsSubstring("heartbeat_seconds: must be between"));
        CHECK_THAT(e.what(), ContainsSubstring("log.level"));
        CHECK_THAT(e.what(), ContainsSubstring("monitor.cpu_high_percent: wrong type"));
        CHECK_THAT(e.what(), ContainsSubstring("commands: must be a list"));
        CHECK_THAT(e.what(), ContainsSubstring("updates.public_key"));
    }
}

TEST_CASE("config: comments are allowed in the file, garbage is not") {
    test::TempDir dir;
    paths::writeFileAtomically(dir.path() / "a.json", "{\n  // the house server\n  \"server_url\": \"https://h\"\n}\n");
    CHECK(agent::Config::load(dir.path() / "a.json").serverUrl == "https://h");
    paths::writeFileAtomically(dir.path() / "b.json", "server_url = x");
    CHECK_THROWS_AS(agent::Config::load(dir.path() / "b.json"), agent::ConfigError);
}

TEST_CASE("config: the example from init is a valid config") {
    const auto example = agent::Config::example("https://home.example.org");
    const auto c = agent::Config::fromJson(example);
    CHECK(c.serverUrl == "https://home.example.org");
    CHECK(c.commands.size() == 3);
}
