#include "agent/agent.hpp"
#include "agent/backoff.hpp"

#include "TempDir.hpp"
#include "fakes/FakeMonitor.hpp"
#include "fakes/FakeTransport.hpp"

#include <catch2/catch_test_macros.hpp>

#include <atomic>
#include <thread>

using namespace hha;
using namespace std::chrono_literals;
using nlohmann::json;

TEST_CASE("backoff: doubles up to the cap, with jitter between half and all of it") {
    double r = 0.0;
    agent::Backoff b(5s, 2min, [&] { return r; });
    CHECK(b.next() == 2500ms);  // r = 0: half of 5 s
    r = 0.999999;
    CHECK(b.next() == 10s);  // r ~ 1: all of 10 s
    CHECK(b.ceiling() == 20s);
    for (int i = 0; i < 40; ++i) b.next();  // no overflow, however long it's down
    CHECK(b.ceiling() == 2min);
    r = 0.5;
    const auto wait = b.next();
    CHECK(wait >= 60s);
    CHECK(wait <= 2min);
    b.reset();
    CHECK(b.ceiling() == 5s);
    CHECK(b.attempts() == 0);
}

TEST_CASE("backoff: real randomness stays in range") {
    agent::Backoff b(100ms, 1s);
    for (int i = 0; i < 200; ++i) {
        const auto top = b.ceiling();
        const auto wait = b.next();
        CHECK(wait >= top / 2);
        CHECK(wait <= top);
    }
}

TEST_CASE("agent: rides out an outage, keeps its events, and says when it's back") {
    const crypto::Bytes secret(32, 0x66);
    std::mutex m;
    std::vector<std::string> eventTypes;
    std::atomic<int> heartbeats{0};
    test::FakeTransport t([&](const net::HttpRequest& r) {
        if (r.url.ends_with("/events")) {
            std::lock_guard lock(m);
            // Parsed into a local first: a range-for over json::parse(...)["events"] would
            // iterate a destroyed temporary (C++20 doesn't extend its lifetime; C++23 does)
            const auto body = json::parse(r.body);
            for (const auto& e : body["events"]) eventTypes.push_back(e["type"]);
        }
        if (r.url.ends_with("/heartbeat")) ++heartbeats;
        const bool hb = r.url.ends_with("/heartbeat");
        return test::signedResponse(secret, r, hb || r.url.ends_with("/events") ? 200 : 204,
                                    hb ? R"({"heartbeat_seconds":3600})" : (r.url.ends_with("/events") ? "{}" : ""));
    });
    net::ApiClient api({.serverUrl = "https://home.example.org"}, t);
    api.setCredentials({"https://home.example.org", "01jd0000000000000000000000", secret, "Home"});
    test::FakeMonitor monitor;
    test::TempDir dir;
    events::Outbox outbox(dir.path() / "outbox.jsonl", 100);

    auto config = agent::Config::fromJson(json::object());
    config.retryBase = 20ms;
    config.retryCap = 80ms;
    agent::Agent a(config, {api, monitor, nullptr, nullptr, {}, &outbox});

    t.goOffline();
    std::jthread worker([&](std::stop_token stop) { a.run(stop); });

    // Retries quickly while offline (not once an hour), and goes on retrying
    for (int i = 0; i < 300 && t.count() < 5; ++i) std::this_thread::sleep_for(10ms);
    CHECK(t.count() >= 5);
    CHECK_FALSE(a.online());
    a.emit(events::Event::make("disk.low", events::Severity::Warning, "C: is nearly full"));
    CHECK(outbox.size() >= 2);  // agent.started and disk.low, waiting

    t.goOffline(false);
    for (int i = 0; i < 300; ++i) {
        {
            std::lock_guard lock(m);
            if (std::find(eventTypes.begin(), eventTypes.end(), "agent.reconnected") != eventTypes.end()) break;
        }
        std::this_thread::sleep_for(10ms);
    }
    CHECK(a.online());

    std::lock_guard lock(m);
    CHECK(std::find(eventTypes.begin(), eventTypes.end(), "agent.started") != eventTypes.end());
    CHECK(std::find(eventTypes.begin(), eventTypes.end(), "disk.low") != eventTypes.end());
    CHECK(std::find(eventTypes.begin(), eventTypes.end(), "agent.reconnected") != eventTypes.end());
    // Back to the server's pace: no more rapid retries
    const auto beats = heartbeats.load();
    std::this_thread::sleep_for(200ms);
    CHECK(heartbeats.load() <= beats + 1);
}

TEST_CASE("agent: a server error backs off like an outage; a refusal does not") {
    const crypto::Bytes secret(32, 0x67);
    std::atomic<int> status{503};
    test::FakeTransport t([&](const net::HttpRequest& r) {
        return test::signedResponse(secret, r, status.load(), status.load() == 200 ? "{}" : R"({"message":"x"})");
    });
    net::ApiClient api({.serverUrl = "https://home.example.org"}, t);
    api.setCredentials({"https://home.example.org", "01jd0000000000000000000000", secret, "Home"});
    test::FakeMonitor monitor;
    auto config = agent::Config::fromJson(json::object());
    config.retryBase = 10ms;
    config.retryCap = 40ms;
    config.heartbeat = 3600s;
    agent::Agent a(config, {api, monitor});

    std::jthread worker([&](std::stop_token stop) { a.run(stop); });
    for (int i = 0; i < 300 && t.count() < 4; ++i) std::this_thread::sleep_for(10ms);
    CHECK_FALSE(a.online());
    CHECK(t.count() >= 4);

    status = 422;  // answered, but refused: not offline, and back to the slow pace
    const auto before = t.count();
    for (int i = 0; i < 300 && t.count() == before; ++i) std::this_thread::sleep_for(10ms);
    std::this_thread::sleep_for(150ms);
    CHECK(t.count() <= before + 2);
    worker.request_stop();
}
