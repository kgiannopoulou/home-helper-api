#include "net/signing.hpp"

#include "core/strings.hpp"

namespace hha::net {

std::string canonicalRequest(std::string_view method, std::string_view path, std::string_view timestamp,
                             std::string_view nonce, std::string_view agentId, std::string_view body) {
    std::string out = "HH-AGENT-V1\n";
    for (char c : method) out.push_back(static_cast<char>(c >= 'a' && c <= 'z' ? c - 32 : c));
    out += "\n";
    out += path;
    out += "\n";
    out += timestamp;
    out += "\n";
    out += nonce;
    out += "\n";
    out += agentId;
    out += "\n";
    out += crypto::sha512Hex(body);
    return out;
}

std::string canonicalResponse(int status, std::string_view requestNonce, std::string_view body) {
    std::string out = "HH-AGENT-V1-RESPONSE\n";
    out += std::to_string(status);
    out += "\n";
    out += requestNonce;
    out += "\n";
    out += crypto::sha512Hex(body);
    return out;
}

RequestSignature signRequest(const crypto::Bytes& secret, std::string_view agentId, std::string_view method,
                             std::string_view path, std::string_view body, std::int64_t unixSeconds,
                             std::string_view nonce) {
    RequestSignature sig;
    sig.agentId = std::string(agentId);
    sig.timestamp = std::to_string(unixSeconds);
    sig.nonce = std::string(nonce);
    sig.signature = crypto::hmacSha512Hex(secret, canonicalRequest(method, path, sig.timestamp, nonce, agentId, body));
    return sig;
}

bool verifyResponse(const crypto::Bytes& secret, int status, std::string_view requestNonce, std::string_view body,
                    std::string_view signatureHex) {
    const auto expected = crypto::hmacSha512Hex(secret, canonicalResponse(status, requestNonce, body));
    return crypto::constantTimeEquals(expected, strings::toLower(strings::trim(signatureHex)));
}

}  // namespace hha::net
