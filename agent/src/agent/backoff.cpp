#include "agent/backoff.hpp"

#include <algorithm>
#include <random>

namespace hha::agent {

Backoff::Backoff(std::chrono::milliseconds base, std::chrono::milliseconds cap, Random random)
    : base_(base), cap_(cap), random_(std::move(random)) {
    if (!random_) {
        random_ = [engine = std::mt19937_64(std::random_device{}())]() mutable {
            return std::uniform_real_distribution<double>(0.0, 1.0)(engine);
        };
    }
}

std::chrono::milliseconds Backoff::ceiling() const {
    // 2^attempt without overflow: past 30 doublings anything is over the cap
    const auto shift = std::min(attempt_, 30);
    const auto grown = base_.count() * (std::int64_t{1} << shift);
    return std::chrono::milliseconds(std::min<std::int64_t>(grown, cap_.count()));
}

std::chrono::milliseconds Backoff::next() {
    const auto top = ceiling();
    ++attempt_;
    // "Equal jitter": half fixed, half random. Spreads the agents out without
    // ever retrying immediately, which full jitter's zero would allow.
    const auto half = top.count() / 2;
    const auto jitter = static_cast<std::int64_t>(random_() * static_cast<double>(top.count() - half + 1));
    return std::chrono::milliseconds(half + std::min<std::int64_t>(jitter, top.count() - half));
}

}  // namespace hha::agent
