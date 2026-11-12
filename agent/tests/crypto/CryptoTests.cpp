#include "crypto/crypto.hpp"

#include "TempDir.hpp"
#include "Vectors.hpp"
#include "core/paths.hpp"

#include <catch2/catch_test_macros.hpp>

#include <set>

using namespace hha;
using namespace hha::crypto;

TEST_CASE("crypto: hex round trip and rejects bad hex") {
    const Bytes bytes{0x00, 0x7f, 0x80, 0xff};
    CHECK(toHex(bytes) == "007f80ff");
    CHECK(fromHex("007F80ff") == bytes);
    CHECK_FALSE(fromHex("abc").has_value());
    CHECK_FALSE(fromHex("zz").has_value());
}

TEST_CASE("crypto: SHA-512 matches the FIPS 180-2 vectors") {
    CHECK(sha512Hex("abc") ==
          "ddaf35a193617abacc417349ae20413112e6fa4e89a97ea20a9eeee64b55d39a"
          "2192992a274fc1a836ba3c23a3feebbd454d4423643ce80e2a9ac94fa54ca49f");
    CHECK(sha512Hex("") ==
          "cf83e1357eefb8bdf1542850d66d8007d620e4050b5715dc83f4a921d36ce9ce"
          "47d0d13c5d85f2b0ff8318d2877eec2f63b931bd47417a81a538327af927da3e");
}

TEST_CASE("crypto: SHA-512 of a file equals SHA-512 of its bytes") {
    test::TempDir dir;
    std::string big(300'000, 'x');
    paths::writeFileAtomically(dir.path() / "f.bin", big);
    CHECK(sha512FileHex(dir.path() / "f.bin") == sha512Hex(big));
    CHECK_THROWS(sha512FileHex(dir.path() / "missing.bin"));
}

TEST_CASE("crypto: HMAC-SHA-512 matches RFC 4231") {
    // Test case 1
    const Bytes key1(20, 0x0b);
    CHECK(hmacSha512Hex(key1, "Hi There") ==
          "87aa7cdea5ef619d4ff0b4241a1d6cb02379f4e2ce4ec2787ad0b30545e17cde"
          "daa833b7d6b8a702038b274eaea3f4e4be9d914eeb61f1702e696c203a126854");
    // Test case 2: a key shorter than the block
    const std::string jefe = "Jefe";
    CHECK(hmacSha512Hex(Bytes(jefe.begin(), jefe.end()), "what do ya want for nothing?") ==
          "164b7a7bfcf819e2e395fbe73b56e0a387bd64222e831fd610270cd7ea250554"
          "9758bf75c05a994a6d034f65f8f0e6fdcaeab1a34d4a6b4b636e070a38bce737");
}

TEST_CASE("crypto: constant-time comparison") {
    CHECK(constantTimeEquals("abcdef", "abcdef"));
    CHECK_FALSE(constantTimeEquals("abcdef", "abcdeg"));
    CHECK_FALSE(constantTimeEquals("abc", "abcd"));
    CHECK(constantTimeEquals("", ""));
}

TEST_CASE("crypto: random bytes, hex and UUIDs are unique and well formed") {
    std::set<std::string> seen;
    for (int i = 0; i < 200; ++i) {
        const auto id = uuid4();
        REQUIRE(id.size() == 36);
        CHECK(id[14] == '4');
        CHECK((id[19] == '8' || id[19] == '9' || id[19] == 'a' || id[19] == 'b'));
        seen.insert(id);
    }
    CHECK(seen.size() == 200);
    CHECK(randomHex(16).size() == 32);
    CHECK(randomBytes(64).size() == 64);
}

TEST_CASE("crypto: Ed25519 matches RFC 8032 test 1") {
    const auto seed = *fromHex("9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60");
    const auto pair = ed25519KeyPairFromSeed(std::span<const std::uint8_t, 32>(seed.data(), 32));
    CHECK(toHex(pair.publicKey) == "d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a");
    const auto sig = ed25519Sign(pair.secretKey, "");
    CHECK(toHex(sig) ==
          "e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e06522490155"
          "5fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b");
    CHECK(ed25519Verify(sig, pair.publicKey, ""));
    CHECK_FALSE(ed25519Verify(sig, pair.publicKey, "x"));
}

TEST_CASE("crypto: Ed25519 verifies a release signed by PHP's sodium") {
    const auto& v = test::signingVectors()["release"];
    const auto key = publicKeyFromHex(v["public_key"].get<std::string>());
    const auto sig = signatureFromHex(v["signature"].get<std::string>());
    REQUIRE(key);
    REQUIRE(sig);
    CHECK(ed25519Verify(*sig, *key, v["manifest"].get<std::string>()));
    CHECK_FALSE(ed25519Verify(*sig, *key, v["manifest"].get<std::string>() + "\n"));

    // and the same seed gives PHP's key pair
    const auto seed = *fromHex(v["seed"].get<std::string>());
    CHECK(toHex(ed25519KeyPairFromSeed(std::span<const std::uint8_t, 32>(seed.data(), 32)).publicKey) ==
          v["public_key"].get<std::string>());
}

TEST_CASE("crypto: keys and signatures of the wrong size are rejected") {
    CHECK_FALSE(publicKeyFromHex("abcd").has_value());
    CHECK_FALSE(signatureFromHex(std::string(127, 'a')).has_value());
}
