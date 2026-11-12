#include "crypto/crypto.hpp"

#include <fstream>
#include <stdexcept>

extern "C" {
#include <monocypher-ed25519.h>
#include <monocypher.h>
}

#ifdef _WIN32
#include <windows.h>
#include <bcrypt.h>
#else
#include <cerrno>
#include <sys/random.h>
#endif

namespace hha::crypto {

namespace {

const auto* bytesOf(std::string_view text) {
    return reinterpret_cast<const std::uint8_t*>(text.data());
}

int hexValue(char c) {
    if (c >= '0' && c <= '9') return c - '0';
    if (c >= 'a' && c <= 'f') return c - 'a' + 10;
    if (c >= 'A' && c <= 'F') return c - 'A' + 10;
    return -1;
}

}  // namespace

std::string toHex(std::span<const std::uint8_t> bytes) {
    static constexpr char kDigits[] = "0123456789abcdef";
    std::string out;
    out.reserve(bytes.size() * 2);
    for (const auto b : bytes) {
        out.push_back(kDigits[b >> 4]);
        out.push_back(kDigits[b & 0x0f]);
    }
    return out;
}

std::optional<Bytes> fromHex(std::string_view hex) {
    if (hex.size() % 2 != 0) {
        return std::nullopt;
    }
    Bytes out;
    out.reserve(hex.size() / 2);
    for (std::size_t i = 0; i < hex.size(); i += 2) {
        const int hi = hexValue(hex[i]);
        const int lo = hexValue(hex[i + 1]);
        if (hi < 0 || lo < 0) {
            return std::nullopt;
        }
        out.push_back(static_cast<std::uint8_t>((hi << 4) | lo));
    }
    return out;
}

std::string sha512Hex(std::string_view data) {
    std::array<std::uint8_t, 64> hash{};
    crypto_sha512(hash.data(), bytesOf(data), data.size());
    return toHex(hash);
}

std::string sha512FileHex(const std::filesystem::path& file) {
    std::ifstream in(file, std::ios::binary);
    if (!in) {
        throw std::runtime_error("cannot read " + file.string());
    }
    crypto_sha512_ctx ctx;
    crypto_sha512_init(&ctx);
    std::array<char, 64 * 1024> buffer{};
    while (in) {
        in.read(buffer.data(), buffer.size());
        const auto n = static_cast<std::size_t>(in.gcount());
        if (n > 0) {
            crypto_sha512_update(&ctx, reinterpret_cast<const std::uint8_t*>(buffer.data()), n);
        }
    }
    if (in.bad()) {
        throw std::runtime_error("error reading " + file.string());
    }
    std::array<std::uint8_t, 64> hash{};
    crypto_sha512_final(&ctx, hash.data());
    return toHex(hash);
}

std::string hmacSha512Hex(std::span<const std::uint8_t> key, std::string_view message) {
    std::array<std::uint8_t, 64> mac{};
    crypto_sha512_hmac(mac.data(), key.data(), key.size(), bytesOf(message), message.size());
    return toHex(mac);
}

bool constantTimeEquals(std::string_view a, std::string_view b) {
    if (a.size() != b.size()) {
        return false;  // lengths of hex MACs are public
    }
    unsigned char diff = 0;
    for (std::size_t i = 0; i < a.size(); ++i) {
        diff |= static_cast<unsigned char>(a[i] ^ b[i]);
    }
    return diff == 0;
}

Bytes randomBytes(std::size_t count) {
    Bytes out(count);
    if (count == 0) return out;
#ifdef _WIN32
    const NTSTATUS status = BCryptGenRandom(nullptr, out.data(), static_cast<ULONG>(out.size()), BCRYPT_USE_SYSTEM_PREFERRED_RNG);
    if (status != 0) {
        throw std::runtime_error("BCryptGenRandom failed");
    }
#else
    std::size_t filled = 0;
    while (filled < count) {
        const auto n = getrandom(out.data() + filled, count - filled, 0);
        if (n < 0) {
            if (errno == EINTR) continue;
            throw std::runtime_error("getrandom failed");
        }
        filled += static_cast<std::size_t>(n);
    }
#endif
    return out;
}

std::string randomHex(std::size_t byteCount) {
    return toHex(randomBytes(byteCount));
}

std::string uuid4() {
    auto b = randomBytes(16);
    b[6] = static_cast<std::uint8_t>((b[6] & 0x0f) | 0x40);  // version 4
    b[8] = static_cast<std::uint8_t>((b[8] & 0x3f) | 0x80);  // RFC 4122 variant
    const auto hex = toHex(b);
    return hex.substr(0, 8) + "-" + hex.substr(8, 4) + "-" + hex.substr(12, 4) + "-" + hex.substr(16, 4) + "-" + hex.substr(20);
}

KeyPair ed25519KeyPairFromSeed(std::span<const std::uint8_t, 32> seed) {
    std::array<std::uint8_t, 32> copy{};
    std::copy(seed.begin(), seed.end(), copy.begin());
    KeyPair pair{};
    crypto_ed25519_key_pair(pair.secretKey.data(), pair.publicKey.data(), copy.data());  // wipes `copy`
    return pair;
}

KeyPair ed25519KeyPair() {
    auto seed = randomBytes(32);
    const auto pair = ed25519KeyPairFromSeed(std::span<const std::uint8_t, 32>(seed.data(), 32));
    wipe(seed.data(), seed.size());
    return pair;
}

Signature ed25519Sign(const SecretKey& secretKey, std::string_view message) {
    Signature signature{};
    crypto_ed25519_sign(signature.data(), secretKey.data(), bytesOf(message), message.size());
    return signature;
}

bool ed25519Verify(const Signature& signature, const PublicKey& publicKey, std::string_view message) {
    return crypto_ed25519_check(signature.data(), publicKey.data(), bytesOf(message), message.size()) == 0;
}

std::optional<PublicKey> publicKeyFromHex(std::string_view hex) {
    const auto bytes = fromHex(hex);
    if (!bytes || bytes->size() != 32) return std::nullopt;
    PublicKey key{};
    std::copy(bytes->begin(), bytes->end(), key.begin());
    return key;
}

std::optional<Signature> signatureFromHex(std::string_view hex) {
    const auto bytes = fromHex(hex);
    if (!bytes || bytes->size() != 64) return std::nullopt;
    Signature sig{};
    std::copy(bytes->begin(), bytes->end(), sig.begin());
    return sig;
}

void wipe(void* data, std::size_t size) {
    crypto_wipe(data, size);
}

}  // namespace hha::crypto
