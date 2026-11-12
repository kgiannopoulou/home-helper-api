#include "update/updater.hpp"

#include "TempDir.hpp"
#include "Vectors.hpp"
#include "agent/agent.hpp"
#include "core/paths.hpp"
#include "core/version.hpp"
#include "fakes/FakeMonitor.hpp"
#include "fakes/FakeTransport.hpp"

#include <catch2/catch_test_macros.hpp>

using namespace hha;
using nlohmann::json;
using Outcome = update::Updater::Outcome;

namespace {

const crypto::Bytes kSecret(32, 0x77);
const std::string kRelease = "the new agent binary, version 99";

struct Keys {
    crypto::KeyPair pair;
    std::string publicHex;
    Keys() {
        std::array<std::uint8_t, 32> seed{};
        seed.fill(7);
        pair = crypto::ed25519KeyPairFromSeed(seed);
        publicHex = crypto::toHex(pair.publicKey);
    }
};

agent::UpdateOffer offer(const Keys& keys, bool install = false, std::string bytes = kRelease,
                         std::string versionText = "99.0.0") {
    agent::UpdateOffer o;
    o.version = std::move(versionText);
    o.platform = std::string(version::platform());
    o.url = "releases/01jrelease00000000000000000/download";
    o.sha512 = crypto::sha512Hex(bytes);
    o.size = bytes.size();
    o.signature =
        crypto::toHex(crypto::ed25519Sign(keys.pair.secretKey, update::manifest(o.version, o.platform, o.sha512, o.size)));
    o.install = install;
    return o;
}

struct Harness {
    Keys keys;
    test::TempDir dir;
    std::string served = kRelease;
    test::FakeTransport transport{[this](const net::HttpRequest& r) {
        return test::signedResponse(kSecret, r, 200, served);
    }};
    std::unique_ptr<net::ApiClient> api;
    events::Outbox outbox{{}, 100};
    std::unique_ptr<update::Updater> updater;

    explicit Harness(bool withKey = true) {
        api = std::make_unique<net::ApiClient>(net::ApiClient::Options{.serverUrl = "https://home.example.org"}, transport);
        api->setCredentials({"https://home.example.org", "01jd0000000000000000000000", kSecret, "Home"});
        updater = std::make_unique<update::Updater>(dir.path() / "updates",
                                                    withKey ? std::optional(keys.publicHex) : std::nullopt, *api,
                                                    transport, outbox);
    }

    std::vector<std::string> eventTypes() const {
        std::vector<std::string> out;
        for (const auto& e : outbox.peek("event", 100)) out.push_back(e.body["type"]);
        return out;
    }
};

}  // namespace

TEST_CASE("updates: the manifest and signature match what PHP's sodium signs") {
    const auto& v = test::signingVectors()["release"];
    CHECK(update::manifest(v["version"], v["platform"], v["sha512"], v["size"]) == v["manifest"].get<std::string>());
    agent::UpdateOffer o;
    o.version = v["version"];
    o.platform = v["platform"];
    o.sha512 = v["sha512"];
    o.size = v["size"];
    o.signature = v["signature"];
    CHECK(update::verifyOffer(o, v["public_key"]));
    o.size += 1;  // any field changed: invalid
    CHECK_FALSE(update::verifyOffer(o, v["public_key"]));
}

TEST_CASE("updates: off without a release key in the config") {
    Harness h(false);
    CHECK(h.updater->handle(offer(h.keys, true)) == Outcome::Ignored);
    CHECK(h.transport.count() == 0);
}

TEST_CASE("updates: announced once, downloaded only when the owner says install") {
    Harness h;
    CHECK(h.updater->handle(offer(h.keys)) == Outcome::Available);
    CHECK(h.updater->handle(offer(h.keys)) == Outcome::Available);
    CHECK(h.eventTypes() == std::vector<std::string>{"update.available"});
    CHECK(h.transport.count() == 0);

    CHECK(h.updater->handle(offer(h.keys, true)) == Outcome::Staged);
    CHECK(h.transport.count() == 1);
    CHECK(h.transport.requests()[0].url == "https://home.example.org/api/agent/v1/releases/01jrelease00000000000000000/download");
    const auto staged = h.updater->staged();
    REQUIRE(staged);
    CHECK(staged->offer.version == "99.0.0");
    CHECK(paths::readFile(staged->file) == kRelease);
}

TEST_CASE("updates: a release the configured key didn't sign is refused before downloading") {
    Harness h;
    Keys other;
    std::array<std::uint8_t, 32> seed{};
    seed.fill(9);
    other.pair = crypto::ed25519KeyPairFromSeed(seed);
    CHECK(h.updater->handle(offer(other, true)) == Outcome::Failed);
    CHECK(h.transport.count() == 0);
    CHECK(h.eventTypes() == std::vector<std::string>{"update.failed"});
    CHECK(h.updater->handle(offer(other, true)) == Outcome::Ignored);  // not tried again
}

TEST_CASE("updates: a download that doesn't match the signed hash or size is refused") {
    Harness h;
    h.served = "something else entirely, version 99";
    CHECK(h.updater->handle(offer(h.keys, true)) == Outcome::Failed);
    CHECK_FALSE(h.updater->staged());

    Harness short_;
    short_.served = kRelease.substr(0, 10);
    CHECK(short_.updater->handle(offer(short_.keys, true)) == Outcome::Failed);
}

TEST_CASE("updates: never a downgrade or the same version, never another platform") {
    Harness h;
    CHECK(h.updater->handle(offer(h.keys, true, kRelease, std::string(version::kVersion))) == Outcome::Ignored);
    CHECK(h.updater->handle(offer(h.keys, true, kRelease, "0.0.1")) == Outcome::Ignored);
    auto wrong = offer(h.keys, true);
    wrong.platform = "plan9-mips";
    wrong.signature = crypto::toHex(
        crypto::ed25519Sign(h.keys.pair.secretKey, update::manifest(wrong.version, wrong.platform, wrong.sha512, wrong.size)));
    CHECK(h.updater->handle(wrong) == Outcome::Failed);
    CHECK(h.transport.count() == 0);
}

TEST_CASE("updates: a staged file changed on disk is not installed") {
    Harness h;
    REQUIRE(h.updater->handle(offer(h.keys, true)) == Outcome::Staged);
    const auto file = h.updater->staged()->file;
    paths::writeFileAtomically(file, "tampered binary, version 99!!");
    CHECK_FALSE(h.updater->staged());

    const auto exe = h.dir.path() / "bin" / "home-helper-agent.exe";
    paths::writeFileAtomically(exe, "current");
    CHECK_FALSE(h.updater->applyStaged(exe));
    CHECK(paths::readFile(exe) == "current");
}

TEST_CASE("updates: installed with the old binary kept, and rolled back") {
    Harness h;
    REQUIRE(h.updater->handle(offer(h.keys, true)) == Outcome::Staged);
    const auto exe = h.dir.path() / "bin" / "home-helper-agent.exe";
    paths::writeFileAtomically(exe, "current");

    CHECK(h.updater->applyStaged(exe) == "99.0.0");
    CHECK(paths::readFile(exe) == kRelease);
    auto old = exe;
    old += ".old";
    CHECK(paths::readFile(old) == "current");
    CHECK_FALSE(h.updater->staged());  // used up

    CHECK(update::Updater::rollback(exe));
    CHECK(paths::readFile(exe) == "current");
    CHECK_FALSE(update::Updater::rollback(exe));
}

TEST_CASE("agent: an install offer stops the loop to restart into the new version") {
    Harness h;
    const auto o = offer(h.keys, true);
    h.transport.setHandler([&](const net::HttpRequest& r) {
        if (r.url.ends_with("/heartbeat")) {
            return test::signedResponse(kSecret, r, 200,
                                        json{{"update",
                                              {{"version", o.version}, {"platform", o.platform}, {"url", o.url},
                                               {"sha512", o.sha512}, {"size", o.size}, {"signature", o.signature},
                                               {"install", true}}}}
                                            .dump());
        }
        if (r.url.ends_with("/download")) return test::signedResponse(kSecret, r, 200, kRelease);
        return test::signedResponse(kSecret, r, r.url.ends_with("/inventory") ? 204 : 200,
                                    r.url.ends_with("/inventory") ? "" : "{}");
    });
    test::FakeMonitor monitor;
    agent::Agent a(agent::Config::fromJson(json::object()), {*h.api, monitor, nullptr, nullptr, {}, nullptr, h.updater.get()});
    std::stop_source stop;
    CHECK(a.run(stop.get_token()) == agent::Agent::StopReason::RestartForUpdate);
    CHECK(h.updater->staged());
}
