#include "agent/credentials.hpp"
#include "agent/enrollment.hpp"

#include "TempDir.hpp"
#include "core/paths.hpp"
#include "fakes/FakeTransport.hpp"

#include <catch2/catch_test_macros.hpp>

using namespace hha;
using hha::test::FakeTransport;
using nlohmann::json;

TEST_CASE("enrollment: codes are normalised and checked before sending") {
    CHECK(agent::normalizeCode("k7qm 2xpa") == "K7QM-2XPA");
    CHECK(agent::normalizeCode("K7QM-2XPA") == "K7QM-2XPA");
    CHECK(agent::normalizeCode("k7qm2xpa") == "K7QM-2XPA");
    CHECK_THROWS_AS(agent::normalizeCode("K7QM-2XP"), std::invalid_argument);
    CHECK_THROWS_AS(agent::normalizeCode("K7QM;2XPA"), std::invalid_argument);
}

TEST_CASE("enrollment: sends what the server needs and keeps what it answers") {
    const std::string secretHex(64, 'a');
    FakeTransport t([&](const net::HttpRequest&) {
        return net::HttpResponse{201, {},
                                 json{{"agent_id", "01jd0000000000000000000000"},
                                      {"secret", secretHex},
                                      {"household", {{"id", "h"}, {"name", "Home"}}}}
                                     .dump()};
    });
    net::ApiClient client({.serverUrl = "https://home.example.org"}, t);
    const auto c = agent::enroll(client, "https://home.example.org", {.code = "k7qm-2xpa", .name = "", .hostname = "MINIPC"});

    CHECK(c.agentId == "01jd0000000000000000000000");
    CHECK(c.household == "Home");
    CHECK(c.secret == crypto::Bytes(32, 0xaa));
    CHECK(c.serverUrl == "https://home.example.org");

    const auto sent = json::parse(t.requests().at(0).body);
    CHECK(sent["code"] == "K7QM-2XPA");
    CHECK(sent["hostname"] == "MINIPC");
    CHECK(sent.contains("platform"));
    CHECK(sent.contains("version"));
    CHECK_FALSE(sent.contains("name"));  // the server uses the hostname
}

TEST_CASE("enrollment: a refused code is an ApiError with its code") {
    FakeTransport t([](const net::HttpRequest&) {
        return net::HttpResponse{422, {}, R"({"error":"invalid_code","message":"nope"})"};
    });
    net::ApiClient client({.serverUrl = "https://home.example.org"}, t);
    try {
        agent::enroll(client, "https://home.example.org", {.code = "AAAA-AAAA", .name = "", .hostname = "h"});
        FAIL("expected ApiError");
    } catch (const net::ApiError& e) {
        CHECK(e.code() == "invalid_code");
    }
}

TEST_CASE("credentials: saved, protected, loaded and erased") {
    test::TempDir dir;
    agent::CredentialStore store(dir.path() / "data" / "credentials.json");
    CHECK_FALSE(store.load().has_value());

    const net::Credentials c{"https://home.example.org", "01jd0000000000000000000000", crypto::Bytes(32, 0x5a), "Home"};
    store.save(c);
    const auto loaded = store.load();
    REQUIRE(loaded);
    CHECK(loaded->agentId == c.agentId);
    CHECK(loaded->serverUrl == c.serverUrl);
    CHECK(loaded->household == "Home");
    CHECK(loaded->secret == c.secret);

    // The secret is never in the file as plain hex
    const auto text = paths::readFile(store.file());
#ifdef _WIN32
    CHECK(text.find(crypto::toHex(c.secret)) == std::string::npos);
#else
    const auto perms = std::filesystem::status(store.file()).permissions();
    CHECK((perms & std::filesystem::perms::group_all) == std::filesystem::perms::none);
    CHECK((perms & std::filesystem::perms::others_all) == std::filesystem::perms::none);
#endif

    store.save(c);  // saving again over a protected file works
    store.erase();
    CHECK_FALSE(store.exists());
}

TEST_CASE("credentials: a damaged file is an error, not an empty identity") {
    test::TempDir dir;
    paths::writeFileAtomically(dir.path() / "credentials.json", "{\"oops\":1}");
    CHECK_THROWS(agent::CredentialStore(dir.path() / "credentials.json").load());
}
