#pragma once

#include <chrono>
#include <cstdint>
#include <functional>

namespace hha::agent {

/// How long to wait before the next try while the server is unreachable:
/// exponential (5 s, 10 s, 20 s… up to the cap) with jitter, so a house full
/// of agents that lost Wi-Fi together don't all come back in the same second.
/// reset() after a success.
class Backoff {
public:
    using Random = std::function<double()>;  // uniform in [0, 1)

    Backoff(std::chrono::milliseconds base, std::chrono::milliseconds cap, Random random = {});

    /// The ceiling for this attempt (base * 2^attempt, capped), without jitter
    std::chrono::milliseconds ceiling() const;
    /// A random wait in [ceiling / 2, ceiling]: never zero, never more than the cap
    std::chrono::milliseconds next();
    void reset() { attempt_ = 0; }
    int attempts() const { return attempt_; }

private:
    std::chrono::milliseconds base_;
    std::chrono::milliseconds cap_;
    Random random_;
    int attempt_ = 0;
};

}  // namespace hha::agent
