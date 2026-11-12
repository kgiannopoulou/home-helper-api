#include "events/outbox.hpp"
#include "events/thresholds.hpp"

#include "TempDir.hpp"
#include "agent/agent.hpp"
#include "core/paths.hpp"
#include "core/time.hpp"
#include "fakes/FakeAudio.hpp"
#include "fakes/FakeMonitor.hpp"
#include "fakes/FakeTransport.hpp"

#include <catch2/catch_test_macros.hpp>
#include <catch2/matchers/catch_matchers_string.hpp>

using namespace hha;
using hha::events::Event;
using hha::events::Severity;
using Catch::Matchers::ContainsSubstring;
using nlohmann::json;

namespace {

std::vector<std::string> types(const std::vector<Event>& events) {
    std::vector<std::string> out;
    for (const auto& e : events) out.push_back(e.type);
    return out;
}

monitor::Metrics cpu(double percent) {
    monitor::Metrics m;
    m.cpuPercent = percent;
    return m;
}

}  // namespace

TEST_CASE("events: well-formed, with a unique id and a time") {
    const auto e = Event::make("disk.low", Severity::Warning, "C:\\ has 4 GB free", {{"mount", "C:\\"}});
    const auto j = e.toJson();
    CHECK(j["id"].get<std::string>().size() == 36);
    CHECK(j["severity"] == "warning");
    CHECK(time::parseIso8601(j["occurred_at"].get<std::string>()).has_value());
    CHECK(Event::make("x", Severity::Info, "").id != e.id);
}

TEST_CASE("outbox: survives a restart, and a line cut short by a power cut") {
    test::TempDir dir;
    const auto file = dir.path() / "outbox.jsonl";
    {
        events::Outbox box(file, 100);
        box.add(Event::make("a", Severity::Info, "first"));
        box.addResult({{"id", "C1"}, {"status", "succeeded"}});
        box.add(Event::make("b", Severity::Info, "second"));
    }
    // Simulate a write cut short at the end of the file
    auto text = paths::readFile(file);
    paths::writeFileAtomically(file, text + "{\"kind\":\"event\",\"bo");

    events::Outbox box(file, 100);
    CHECK(box.size() == 3);
    const auto evs = box.peek("event", 10);
    REQUIRE(evs.size() == 2);
    CHECK(evs[0].body["message"] == "first");
    CHECK(box.peek("result", 10)[0].body["id"] == "C1");

    box.remove("event", {evs[0].body["id"].get<std::string>()});
    CHECK(events::Outbox(file, 100).size() == 2);
}

TEST_CASE("outbox: when full, the oldest events go and are counted; results never") {
    test::TempDir dir;
    events::Outbox box(dir.path() / "o.jsonl", 3);
    box.addResult({{"id", "R"}});
    for (int i = 0; i < 5; ++i) box.add(Event::make("e" + std::to_string(i), Severity::Info, ""));
    CHECK(box.size() == 3);
    CHECK(box.dropped() == 3);
    CHECK(box.peek("result", 10).size() == 1);
    CHECK(box.peek("event", 10).back().body["type"] == "e4");
    CHECK(events::Outbox(dir.path() / "o.jsonl", 3).dropped() == 3);  // the count is kept too
}

TEST_CASE("thresholds: a spike is not an event; a sustained reading is, once") {
    events::ThresholdWatcher w({.cpuHighPercent = 90, .sustainSamples = 3});
    CHECK(w.feed(cpu(99)).empty());
    CHECK(w.feed(cpu(20)).empty());  // a spike: the count starts again
    CHECK(w.feed(cpu(95)).empty());
    CHECK(w.feed(cpu(95)).empty());
    CHECK(types(w.feed(cpu(95))) == std::vector<std::string>{"cpu.high"});
    CHECK(w.feed(cpu(97)).empty());  // still high: no repeat
}

TEST_CASE("thresholds: back to normal only clearly under the line, so it doesn't flap") {
    events::ThresholdWatcher w({.cpuHighPercent = 90, .sustainSamples = 1});
    CHECK(types(w.feed(cpu(95))) == std::vector<std::string>{"cpu.high"});
    CHECK(w.feed(cpu(85)).empty());  // within the 10-point margin
    CHECK(w.feed(cpu(95)).empty());
    CHECK(types(w.feed(cpu(50))) == std::vector<std::string>{"cpu.normal"});
}

TEST_CASE("thresholds: disks low on space, per disk, critical when very low") {
    events::ThresholdWatcher w({.diskLowFreePercent = 10, .sustainSamples = 1});
    monitor::Metrics m;
    m.disks = {{"C:\\", 1000, 80, ""}, {"D:\\", 1000, 900, ""}};
    auto out = w.feed(m);
    REQUIRE(out.size() == 1);
    CHECK(out[0].type == "disk.low");
    CHECK(out[0].severity == Severity::Warning);
    CHECK(out[0].data["mount"] == "C:\\");
    CHECK_THAT(out[0].message, ContainsSubstring("free (8%)"));

    events::ThresholdWatcher w2({.diskLowFreePercent = 10, .sustainSamples = 1});
    m.disks = {{"/", 1000, 30, ""}};
    CHECK(w2.feed(m)[0].severity == Severity::Critical);
}

TEST_CASE("thresholds: the hottest sensor, named") {
    events::ThresholdWatcher w({.temperatureHighCelsius = 85, .sustainSamples = 1});
    monitor::Metrics m;
    m.temperatures = {{"CPU", 70}, {"nvme", 88}};
    const auto out = w.feed(m);
    REQUIRE(out.size() == 1);
    CHECK(out[0].type == "temperature.high");
    CHECK_THAT(out[0].message, ContainsSubstring("nvme at 88"));
}

TEST_CASE("agent: events go out with the heartbeat and leave the outbox when accepted") {
    const crypto::Bytes secret(32, 0x55);
    std::vector<json> posted;
    bool serverUp = true;
    test::FakeTransport t([&](const net::HttpRequest& r) {
        if (!serverUp) throw net::TransportError("connect: cannot connect");
        if (r.url.ends_with("/events")) posted.push_back(json::parse(r.body));
        const bool hb = r.url.ends_with("/heartbeat");
        return test::signedResponse(secret, r, hb || r.url.ends_with("/events") ? 200 : 204,
                                    hb ? "{}" : (r.url.ends_with("/events") ? R"({"accepted":1})" : ""));
    });
    net::ApiClient api({.serverUrl = "https://home.example.org"}, t);
    api.setCredentials({"https://home.example.org", "01jd0000000000000000000000", secret, "Home"});
    test::FakeMonitor monitor;
    monitor.metrics.cpuPercent = 99;
    test::FakeAudio audioDevices;
    auto config = agent::Config::fromJson(json::object());
    config.thresholds.sustainSamples = 1;
    agent::Agent a(config, {api, monitor, &audioDevices});

    a.beat();
    REQUIRE(posted.size() == 1);
    const auto sent = types([&] {
        std::vector<Event> evs;
        for (const auto& e : posted[0]["events"]) evs.push_back({e["id"], e["type"], Severity::Info, "", {}, ""});
        return evs;
    }());
    CHECK(sent == std::vector<std::string>{"agent.started", "cpu.high"});
    CHECK(a.outbox().size() == 0);

    // Offline: new events wait in the outbox, and the next heartbeat says how many
    serverUp = false;
    a.emit(Event::make("disk.low", Severity::Warning, "C: is full"));
    CHECK_THROWS_AS(a.beat(), net::TransportError);
    CHECK(a.outbox().size() == 1);

    serverUp = true;
    a.beat();
    const auto heartbeat = [&] {
        json last;
        for (const auto& r : t.requests()) {
            if (r.url.ends_with("/heartbeat")) last = json::parse(r.body);
        }
        return last;
    }();
    CHECK(heartbeat["outbox"]["pending"] == 1);
    CHECK(posted.back()["events"][0]["type"] == "disk.low");
    CHECK(a.outbox().size() == 0);
}
