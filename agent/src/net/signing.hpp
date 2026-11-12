#pragma once

#include "crypto/crypto.hpp"

#include <cstdint>
#include <string>
#include <string_view>

/// Request and response signatures, docs/protocol.md#signatures. The PHP side
/// (App\Agents\RequestSignature) builds exactly the same strings; both test
/// suites check tests/vectors/signing.json.
namespace hha::net {

inline constexpr std::string_view kHeaderAgent = "X-HH-Agent";
inline constexpr std::string_view kHeaderTimestamp = "X-HH-Timestamp";
inline constexpr std::string_view kHeaderNonce = "X-HH-Nonce";
inline constexpr std::string_view kHeaderSignature = "X-HH-Signature";

struct RequestSignature {
    std::string agentId;
    std::string timestamp;  // unix seconds
    std::string nonce;      // 32 hex characters
    std::string signature;  // HMAC-SHA-512, 128 hex characters
};

/// HH-AGENT-V1 \n METHOD \n /path \n timestamp \n nonce \n agent id \n sha512(body)
std::string canonicalRequest(std::string_view method, std::string_view path, std::string_view timestamp,
                             std::string_view nonce, std::string_view agentId, std::string_view body);

/// HH-AGENT-V1-RESPONSE \n status \n request nonce \n sha512(body)
std::string canonicalResponse(int status, std::string_view requestNonce, std::string_view body);

RequestSignature signRequest(const crypto::Bytes& secret, std::string_view agentId, std::string_view method,
                             std::string_view path, std::string_view body, std::int64_t unixSeconds,
                             std::string_view nonce);

/// True when `signatureHex` is the server's signature of this response to
/// the request that carried `requestNonce`.
bool verifyResponse(const crypto::Bytes& secret, int status, std::string_view requestNonce, std::string_view body,
                    std::string_view signatureHex);

}  // namespace hha::net
