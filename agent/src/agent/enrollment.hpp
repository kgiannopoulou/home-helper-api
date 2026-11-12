#pragma once

#include "net/api_client.hpp"

#include <string>

namespace hha::agent {

struct EnrollRequest {
    std::string code;
    std::string name;      // shown on the dashboard; the hostname when empty
    std::string hostname;
};

/// Trades a pairing code for credentials (docs/protocol.md#post-enroll). The
/// client must point at the server and carry no credentials yet. Throws
/// net::ApiError (invalid_code → Rejected) or net::TransportError.
net::Credentials enroll(net::ApiClient& client, const std::string& serverUrl, const EnrollRequest& request);

/// "k7qm 2xpa" → "K7QM-2XPA"; std::invalid_argument when it can't be a code
std::string normalizeCode(const std::string& code);

}  // namespace hha::agent
