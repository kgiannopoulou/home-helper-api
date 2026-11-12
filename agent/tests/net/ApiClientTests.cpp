#include "net/api_client.hpp"

#include "fakes/FakeTransport.hpp"

#include <catch2/catch_test_macros.hpp>

using namespace hha;
using hha::test::FakeTransport;
using hha::test::requestHeader;
using hha::test::signedResponse;
using nlohmann::json;

namespace {

const crypto::Bytes kSecret(32, 0x42);

net::Credentials credentials() {
    return {"https://home.example.org", "01JAGENT000000000000000000", kSecret, "Home"};
}

std::unique_ptr<net::ApiClient> client(FakeTransport& transport, std::int64_t now = 1800000000) {
    auto c = std::make_unique<net::ApiClient>(net::ApiClient::Options{.serverUrl = "https://home.example.org/"}, transport,
                                              [now] { return now; });
    c->setCredentials(credentials());
    return c;
}

}  // namespace

TEST_CASE("api client: only https, except to this computer") {
    FakeTransport t;
    CHECK_THROWS_AS(net::ApiClient({.serverUrl = "http://home.example.org"}, t), std::invalid_argument);
    CHECK_THROWS_AS(net::ApiClient({.serverUrl = "not a url"}, t), std::invalid_argument);
    CHECK_NOTHROW(net::ApiClient({.serverUrl = "http://localhost:8077"}, t));
    CHECK_NOTHROW(net::ApiClient({.serverUrl = "http://192.168.1.10", .allowInsecureHttp = true}, t));
}

TEST_CASE("api client: endpoints sit under /api/agent/v1, below any sub-path") {
    FakeTransport t;
    net::ApiClient c({.serverUrl = "https://example.org/home/?x=1"}, t);
    CHECK(c.urlFor("heartbeat") == "https://example.org/home/api/agent/v1/heartbeat");
}

TEST_CASE("api client: signs every request and accepts a signed answer") {
    FakeTransport t([](const net::HttpRequest& r) { return signedResponse(kSecret, r, 200, R"({"ok":true})"); });
    auto c = client(t);

    const auto reply = c->post("heartbeat", {{"status", "online"}});
    CHECK(reply["ok"] == true);

    const auto request = t.requests().at(0);
    CHECK(request.method == "POST");
    CHECK(request.url == "https://home.example.org/api/agent/v1/heartbeat");
    CHECK(request.body == R"({"status":"online"})");
    CHECK(requestHeader(request, "X-HH-Agent") == "01JAGENT000000000000000000");
    CHECK(requestHeader(request, "X-HH-Timestamp") == "1800000000");
    const auto nonce = requestHeader(request, "X-HH-Nonce");
    REQUIRE(nonce);
    CHECK(nonce->size() == 32);

    const auto expected = net::signRequest(kSecret, "01JAGENT000000000000000000", "POST", "/api/agent/v1/heartbeat",
                                           request.body, 1'800'000'000, *nonce);
    CHECK(requestHeader(request, "X-HH-Signature") == expected.signature);
}

TEST_CASE("api client: a new nonce for every request") {
    FakeTransport t([](const net::HttpRequest& r) { return signedResponse(kSecret, r, 200, "{}"); });
    auto c = client(t);
    c->get("commands");
    c->get("commands");
    CHECK(requestHeader(t.requests()[0], "X-HH-Nonce") != requestHeader(t.requests()[1], "X-HH-Nonce"));
}

TEST_CASE("api client: an unsigned or wrongly signed success is never trusted") {
    FakeTransport t([](const net::HttpRequest&) { return net::HttpResponse{200, {}, R"({"commands":[]})"}; });
    auto c = client(t);
    try {
        c->post("heartbeat", json::object());
        FAIL("expected ApiError");
    } catch (const net::ApiError& e) {
        CHECK(e.kind() == net::ApiError::Kind::BadSignature);
    }

    // Signed, but for another request (a replayed response)
    t.setHandler([](const net::HttpRequest& r) {
        auto other = r;
        other.headers = {{"X-HH-Nonce", "00000000000000000000000000000000"}};
        return signedResponse(kSecret, other, 200, "{}");
    });
    CHECK_THROWS_AS(c->post("heartbeat", json::object()), net::ApiError);

    // Signed with another key
    t.setHandler([](const net::HttpRequest& r) { return signedResponse(crypto::Bytes(32, 1), r, 200, "{}"); });
    CHECK_THROWS_AS(c->post("heartbeat", json::object()), net::ApiError);
}

TEST_CASE("api client: errors are classified") {
    FakeTransport t;
    auto c = client(t);
    auto kindFor = [&](int status, std::string body) {
        t.setHandler([=](const net::HttpRequest&) { return net::HttpResponse{status, {}, body}; });
        try {
            c->post("events", json::object());
        } catch (const net::ApiError& e) {
            return e.kind();
        }
        FAIL("expected ApiError");
        return net::ApiError::Kind::Rejected;
    };
    CHECK(kindFor(401, R"({"error":"bad_signature"})") == net::ApiError::Kind::Unauthorized);
    CHECK(kindFor(401, R"({"error":"agent_revoked"})") == net::ApiError::Kind::Revoked);
    CHECK(kindFor(422, R"({"message":"invalid"})") == net::ApiError::Kind::Rejected);
    CHECK(kindFor(429, "") == net::ApiError::Kind::RateLimited);
    CHECK(kindFor(503, "<html>") == net::ApiError::Kind::Server);
}

TEST_CASE("api client: corrects its clock once after a clock_skew rejection") {
    FakeTransport t([](const net::HttpRequest& r) {
        if (requestHeader(r, "X-HH-Timestamp") == "1000") {
            return net::HttpResponse{401, {}, R"({"error":"clock_skew","server_time":1600})"};
        }
        return signedResponse(kSecret, r, 200, "{}");
    });
    auto c = client(t, 1000);
    CHECK_NOTHROW(c->post("heartbeat", json::object()));
    REQUIRE(t.count() == 2);
    CHECK(requestHeader(t.requests()[1], "X-HH-Timestamp") == "1600");
    CHECK(c->clockOffset() == 600);
}

TEST_CASE("api client: no answer at all is a TransportError (offline)") {
    FakeTransport t;
    t.goOffline();
    auto c = client(t);
    CHECK_THROWS_AS(c->post("heartbeat", json::object()), net::TransportError);
}

TEST_CASE("api client: enrollment is unsigned, signed calls need credentials") {
    FakeTransport t([](const net::HttpRequest&) { return net::HttpResponse{201, {}, R"({"agent_id":"x"})"}; });
    net::ApiClient c({.serverUrl = "https://home.example.org"}, t);
    CHECK(c.enroll({{"code", "ABCD-EFGH"}})["agent_id"] == "x");
    CHECK_FALSE(requestHeader(t.requests()[0], "X-HH-Signature").has_value());
    CHECK_THROWS_AS(c.get("commands"), std::logic_error);
}

TEST_CASE("api client: invalid UTF-8 in a body is replaced, not thrown") {
    CHECK(net::dumpJson(json{{"out", std::string("caf\xe9")}}) == "{\"out\":\"caf\xef\xbf\xbd\"}");
}
