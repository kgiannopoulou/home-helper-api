#include "audio/audio_devices.hpp"

#include "agent/agent.hpp"
#include "agent/inventory.hpp"
#include "fakes/FakeAudio.hpp"
#include "fakes/FakeMonitor.hpp"
#include "fakes/FakeTransport.hpp"

#include <catch2/catch_test_macros.hpp>

using namespace hha;
using hha::audio::Direction;
using nlohmann::json;

TEST_CASE("audio: ALSA devices from /proc/asound") {
    const auto devices = audio::parseAsound(
        " 0 [PCH            ]: HDA-Intel - HDA Intel PCH\n"
        "                      HDA Intel PCH at 0xf7f10000 irq 32\n"
        " 1 [Microphone     ]: USB-Audio - Blue Yeti\n",
        "00-00: ALC892 Analog : ALC892 Analog : playback 1 : capture 1\n"
        "00-03: HDMI 0 : HDMI 0 : playback 1\n"
        "01-00: USB Audio : USB Audio : capture 1\n");
    REQUIRE(devices.size() == 4);
    // inputs first
    CHECK(devices[0].id == "hw:0,0/capture");
    CHECK(devices[0].name == "HDA Intel PCH, ALC892 Analog");
    CHECK(devices[0].direction == Direction::Input);
    CHECK(devices[1].id == "hw:1,0/capture");
    CHECK(devices[1].name == "Blue Yeti, USB Audio");
    CHECK(devices[2].id == "hw:0,0/playback");
    CHECK(devices[3].id == "hw:0,3/playback");
    CHECK(audio::parseAsound("", "").empty());
    CHECK(audio::parseAsound("--- no soundcards ---", "garbage\n").empty());
}

TEST_CASE("audio: what appeared and what went away") {
    const std::vector<audio::AudioDevice> before = {{"a", "Mic", Direction::Input, true, "active"},
                                                    {"b", "Speakers", Direction::Output, true, "active"}};
    auto after = before;
    after[0].state = "unplugged";  // a state change is not a new device
    after.push_back({"c", "Headset mic", Direction::Input, false, "active"});
    after.erase(after.begin() + 1);

    const auto changes = audio::diff(before, after);
    REQUIRE(changes.added.size() == 1);
    CHECK(changes.added[0].id == "c");
    REQUIRE(changes.removed.size() == 1);
    CHECK(changes.removed[0].id == "b");
    CHECK(audio::diff(before, before).empty());
}

TEST_CASE("audio: listing this computer's devices never fails") {
    auto discovery = audio::makeAudioDiscovery();
    const auto devices = discovery->list();  // CI machines have none; that's fine
    for (const auto& d : devices) {
        CHECK_FALSE(d.id.empty());
        CHECK_FALSE(d.name.empty());
    }
}

TEST_CASE("inventory: what the server learns, and a stable hash") {
    test::FakeMonitor monitor;
    test::FakeAudio fakeAudio;
    const auto inventory = agent::buildInventory(monitor.info(), fakeAudio.list(),
                                                 json::array({{{"key", "ping_router"}, {"sensitive", false}}}));
    CHECK(inventory["hostname"] == "TESTPC");
    CHECK(inventory["cpu"]["cores"] == 4);
    CHECK(inventory["audio"][0]["direction"] == "input");
    CHECK(inventory["audio"][0]["default"] == true);
    CHECK(inventory["commands"][0]["key"] == "ping_router");
    CHECK(agent::inventoryHash(inventory).size() == 128);
    CHECK(agent::inventoryHash(inventory) == agent::inventoryHash(json::parse(inventory.dump())));
}

TEST_CASE("agent: sends the inventory first, then only when it changes or is asked for") {
    const crypto::Bytes secret(32, 0x22);
    bool wanted = false;
    test::FakeTransport t([&](const net::HttpRequest& r) {
        const json body = r.url.ends_with("/inventory") ? json(nullptr) : json{{"inventory_wanted", wanted}};
        return test::signedResponse(secret, r, r.url.ends_with("/inventory") ? 204 : 200,
                                    body.is_null() ? "" : body.dump());
    });
    net::ApiClient api({.serverUrl = "https://home.example.org"}, t);
    api.setCredentials({"https://home.example.org", "01jd0000000000000000000000", secret, "Home"});
    test::FakeMonitor monitor;
    test::FakeAudio fakeAudio;
    agent::Agent a(agent::Config::fromJson(json::object()), {api, monitor, &fakeAudio});

    const auto count = [&](const std::string& endpoint) {
        int n = 0;
        for (const auto& r : t.requests()) n += r.url.ends_with("/" + endpoint);
        return n;
    };

    a.beat();
    CHECK(count("inventory") == 1);
    CHECK(t.requests()[0].url.ends_with("/inventory"));
    REQUIRE(a.sentInventoryHash());
    CHECK(json::parse(t.requests()[1].body)["inventory_hash"] == *a.sentInventoryHash());

    a.beat();  // nothing changed
    CHECK(count("inventory") == 1);

    wanted = true;  // the server lost it
    a.beat();
    CHECK(count("inventory") == 2);
}
