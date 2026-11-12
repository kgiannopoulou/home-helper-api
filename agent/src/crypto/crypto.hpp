#pragma once

#include <array>
#include <cstdint>
#include <filesystem>
#include <optional>
#include <span>
#include <string>
#include <string_view>
#include <vector>

/// Thin, typed wrappers over Monocypher and the operating system's random
/// number generator. Nothing here is home-made cryptography: SHA-512,
/// HMAC-SHA-512 and Ed25519 come from Monocypher 4.0.2 (third_party/).
namespace hha::crypto {

using Bytes = std::vector<std::uint8_t>;

std::string toHex(std::span<const std::uint8_t> bytes);
/// Lower or upper case; nullopt for odd lengths or non-hex characters.
std::optional<Bytes> fromHex(std::string_view hex);

std::string sha512Hex(std::string_view data);
/// Streams the file; throws std::runtime_error when it cannot be read.
std::string sha512FileHex(const std::filesystem::path& file);

std::string hmacSha512Hex(std::span<const std::uint8_t> key, std::string_view message);

/// Same length and same bytes, in time that does not depend on where they differ.
bool constantTimeEquals(std::string_view a, std::string_view b);

/// From the OS: BCryptGenRandom on Windows, getrandom(2) on Linux. Throws if unavailable.
Bytes randomBytes(std::size_t count);
std::string randomHex(std::size_t byteCount);

/// A random (version 4) UUID, "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".
std::string uuid4();

// --- Ed25519 (RFC 8032), for signed releases --------------------------------

using PublicKey = std::array<std::uint8_t, 32>;
/// seed (32) followed by the public key (32), as libsodium and PHP's sodium_* use
using SecretKey = std::array<std::uint8_t, 64>;
using Signature = std::array<std::uint8_t, 64>;

struct KeyPair {
    PublicKey publicKey;
    SecretKey secretKey;
};

KeyPair ed25519KeyPair();
/// From a 32-byte seed (for tests with known vectors).
KeyPair ed25519KeyPairFromSeed(std::span<const std::uint8_t, 32> seed);
Signature ed25519Sign(const SecretKey& secretKey, std::string_view message);
bool ed25519Verify(const Signature& signature, const PublicKey& publicKey, std::string_view message);

std::optional<PublicKey> publicKeyFromHex(std::string_view hex);
std::optional<Signature> signatureFromHex(std::string_view hex);

/// Overwrites memory that held a secret.
void wipe(void* data, std::size_t size);

}  // namespace hha::crypto
