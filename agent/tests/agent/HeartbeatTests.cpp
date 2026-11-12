#include "agent/agent.hpp"
#include "agent/heartbeat.hpp"

#include "core/time.hpp"
#include "fakes/FakeMonitor.hpp"
#include "fakes/FakeTransport.hpp"

#include <catch2/catch_test_macros.hpp>

#include <thread>

using namespace hha;
using namespace std::chrono_literals;
using hha::test::FakeMonitor;
using hha::test::FakeTransport;
using hha::test::signedResponse;
using nlohmann::json;

namespace {

const crypto::Bytes kSecret(32, 0x11);

struct Harness {
    FakeTransport transport;
    FakeMonitor monitor;
    std::unique_ptr<net::ApiClient> api;

    Harness() {
        api = std::make_unique<net::ApiClient>(net::ApiClient::Options{.serverUrl = "https://home.example.org"}, transport);
        api->setCredentials({"https://home.example.org", "01jd0000000000000000000000", kSecret, "Home"});
    }

    void reply(json body) {
        transport.setHandler([body](const net::HttpRequest& r) { return signedResponse(kSecret, r, 200, body.dump()); });
    }

    agent::Config config() {
        auto c = agent::Config::fromJson(json::object());
        c.heartbeat = 30s;
        return c;
    }
};

}  // namespace

TEST_CASE("time: ISO 8601 both ways, without a time zone database") {
    const auto t = time::parseIso8601("2026-11-12T10:00:00.123Z");
    REQUIRE(t);
    CHECK(time::iso8601(*t) == "2026-11-12T10:00:00.123Z");
    CHECK(time::unixSeconds(*time::parseIso8601("1970-01-01T00:00:00Z")) == 0);
    CHECK(time::unixSeconds(*time::parseIso8601("2000-02-29T12:00:00+00:00")) == 951825600);
    CHECK_FALSE(time::parseIso8601("2026-11-12 10:00"));
    CHECK_FALSE(time::parseIso8601("2026-13-12T10:00:00Z"));
    CHECK_FALSE(time::parseIso8601("2026-11-12T10:00:00+02:00"));
    CHECK(time::humanDuration(45s) == "45 s");
    CHECK(time::humanDuration(3h + 4min) == "3 h 4 min");
}

TEST_CASE("metrics: JSON leaves out what the platform can't tell") {
    monitor::Metrics m;
    CHECK(m.toJson() == json::object());
    m.cpuPercent = 12.3456;
    m.temperatures = {{"CPU", 54.04}, {"NVMe", 61.25}};
    m.disks = {{"/", 100, 25, "ext4"}};
    const auto j = m.toJson();
    CHECK(j["cpu_percent"] == 12.35);
    CHECK(j["temperatures"][1]["celsius"] == 61.3);
    CHECK(j["disks"][0] == json{{"mount", "/"}, {"total_bytes", 100}, {"free_bytes", 25}});
    CHECK(m.hottest() == 61.25);
    CHECK(m.disks[0].usedPercent() == 75.0);
}

TEST_CASE("heartbeat reply: commands and offers are read strictly, the rest leniently") {
    const auto reply = agent::HeartbeatReply::parse(json::parse(R"({
        "server_time": 1794470400, "heartbeat_seconds": 1, "inventory_wanted": true, "something_new": 1,
        "commands": [
            {"id": "c1", "key": "ping_router", "params": {"host": "192.168.1.1"}, "sensitive": false, "expires_at": "x"},
            {"key": "no id"},
            "junk"
        ],
        "update": {"version": "0.6.0", "url": "https://x"}
    })"));
    CHECK(reply.serverTime == 1794470400);
    CHECK(reply.heartbeat == 5s);  // clamped
    CHECK(reply.inventoryWanted);
    REQUIRE(reply.commands.size() == 1);
    CHECK(reply.commands[0].params["host"] == "192.168.1.1");
    CHECK_FALSE(reply.update.has_value());
    CHECK(reply.problems.size() == 3);
}

TEST_CASE("agent: a heartbeat carries version, uptime and readings") {
    Harness h;
    h.reply({{"server_time", 1}, {"heartbeat_seconds", 15}, {"commands", json::array()}});
    agent::Agent a(h.config(), {*h.api, h.monitor});

    a.beat();
    const auto sent = json::parse(h.transport.requests().at(0).body);
    CHECK(sent.contains("version"));
    CHECK(sent.contains("platform"));
    CHECK(sent["metrics"]["cpu_percent"] == 10.0);
    CHECK(sent["metrics"]["memory"]["total_bytes"] == 16'000'000'000ULL);
    CHECK(time::parseIso8601(sent["started_at"].get<std::string>()).has_value());
    CHECK(a.interval() == 15s);  // the server sets the pace
}

TEST_CASE("agent: a broken sensor still sends the heartbeat, without readings") {
    Harness h;
    h.reply({{"heartbeat_seconds", 30}});
    h.monitor.broken = true;
    agent::Agent a(h.config(), {*h.api, h.monitor});
    a.beat();
    CHECK_FALSE(json::parse(h.transport.requests().at(0).body).contains("metrics"));
}

TEST_CASE("agent: run keeps beating until stopped, and stops for good when revoked") {
    Harness h;
    h.reply({{"heartbeat_seconds", 5}});
    agent::Agent a(h.config(), {*h.api, h.monitor});

    std::jthread worker([&](std::stop_token stop) { a.run(stop); });
    for (int i = 0; i < 200 && h.transport.count() < 1; ++i) std::this_thread::sleep_for(10ms);
    a.wake();  // an immediate second beat
    for (int i = 0; i < 200 && h.transport.count() < 2; ++i) std::this_thread::sleep_for(10ms);
    CHECK(h.transport.count() >= 2);

    // Revoked: the loop returns by itself
    h.transport.setHandler([](const net::HttpRequest& r) {
        return signedResponse(kSecret, r, 401, R"({"error":"agent_revoked"})");
    });
    a.wake();
    worker.join();
    SUCCEED("run() returned after agent_revoked");
}
