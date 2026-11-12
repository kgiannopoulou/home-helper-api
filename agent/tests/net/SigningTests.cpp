#include "net/signing.hpp"

#include "Vectors.hpp"
#include "net/http.hpp"

#include <catch2/catch_test_macros.hpp>

using namespace hha;

namespace {
crypto::Bytes vectorSecret() {
    return *crypto::fromHex(test::signingVectors()["secret_hex"].get<std::string>());
}
}  // namespace

TEST_CASE("signing: request strings and signatures match the PHP vectors") {
    const auto secret = vectorSecret();
    for (const auto& v : test::signingVectors()["requests"]) {
        const auto method = v["method"].get<std::string>();
        const auto path = v["path"].get<std::string>();
        const auto body = v["body"].get<std::string>();
        INFO(method << " " << path);
        CHECK(net::canonicalRequest(method, path, v["timestamp"].get<std::string>(), v["nonce"].get<std::string>(),
                                    v["agent_id"].get<std::string>(), body) == v["canonical"].get<std::string>());
        const auto sig = net::signRequest(secret, v["agent_id"].get<std::string>(), method, path, body,
                                          std::stoll(v["timestamp"].get<std::string>()), v["nonce"].get<std::string>());
        CHECK(sig.signature == v["signature"].get<std::string>());
    }
}

TEST_CASE("signing: the method is upper-cased, so get and GET sign the same") {
    CHECK(net::canonicalRequest("get", "/p", "1", "n", "a", "") == net::canonicalRequest("GET", "/p", "1", "n", "a", ""));
}

TEST_CASE("signing: responses from the PHP vectors verify, tampered ones do not") {
    const auto secret = vectorSecret();
    for (const auto& v : test::signingVectors()["responses"]) {
        const int status = v["status"].get<int>();
        const auto nonce = v["request_nonce"].get<std::string>();
        const auto body = v["body"].get<std::string>();
        const auto signature = v["signature"].get<std::string>();
        CHECK(net::canonicalResponse(status, nonce, body) == v["canonical"].get<std::string>());
        CHECK(net::verifyResponse(secret, status, nonce, body, signature));
        CHECK_FALSE(net::verifyResponse(secret, status + 1, nonce, body, signature));          // another status
        CHECK_FALSE(net::verifyResponse(secret, status, (nonce[0] == '0' ? "1" : "0") + nonce.substr(1), body, signature));  // another request
        CHECK_FALSE(net::verifyResponse(secret, status, nonce, body + " ", signature));        // another body
        auto wrongKey = secret;
        wrongKey[0] ^= 1;
        CHECK_FALSE(net::verifyResponse(wrongKey, status, nonce, body, signature));
    }
}

TEST_CASE("url: parses what the agent is configured with") {
    auto url = net::Url::parse("https://Home.Example.org/sub/");
    REQUIRE(url);
    CHECK(url->host == "home.example.org");
    CHECK(url->port == 443);
    CHECK(url->path == "/sub/");
    CHECK(url->origin() == "https://home.example.org");

    url = net::Url::parse("http://127.0.0.1:8077");
    REQUIRE(url);
    CHECK(url->isLoopback());
    CHECK(url->origin() == "http://127.0.0.1:8077");

    url = net::Url::parse("http://[::1]:9000/x?y=1");
    REQUIRE(url);
    CHECK(url->host == "::1");
    CHECK(url->path == "/x?y=1");
    CHECK(url->origin() == "http://[::1]:9000");
}

TEST_CASE("url: rejects other schemes, credentials and bad ports") {
    CHECK_FALSE(net::Url::parse("ftp://example.org"));
    CHECK_FALSE(net::Url::parse("example.org"));
    CHECK_FALSE(net::Url::parse("https://user:pw@example.org"));
    CHECK_FALSE(net::Url::parse("https://example.org:0"));
    CHECK_FALSE(net::Url::parse("https://example.org:70000"));
    CHECK_FALSE(net::Url::parse("https://:443"));
}
