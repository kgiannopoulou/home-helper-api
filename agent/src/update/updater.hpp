#pragma once

#include "agent/heartbeat.hpp"
#include "events/outbox.hpp"
#include "net/api_client.hpp"

#include <filesystem>
#include <optional>
#include <set>
#include <string>

/// Signed updates (docs/updates.md).
///
///   1. A release is signed with Ed25519 on the server side (php artisan agent:release):
///      the signature covers the version, the platform, the file's SHA-512 and its size.
///   2. The heartbeat offers it. The agent checks the signature against the public key
///      in its *own* config, so a compromised server can't offer a binary the key
///      didn't sign. It reports update.available and waits.
///   3. Only after the household owner presses Install does the offer carry
///      install: true. The agent downloads it, checks size, SHA-512 and signature,
///      and stages it.
///   4. On restart the staged file is checked again, the running binary is kept as
///      .old, and the new one takes its place (Windows: by the agent; Linux: by the
///      unit's privileged ExecStartPre). `update rollback` puts the old one back.
namespace hha::update {

/// HH-AGENT-RELEASE-V1 \n version \n platform \n sha512 \n size
std::string manifest(const std::string& version, const std::string& platform, const std::string& sha512,
                     std::uint64_t size);

/// The signature over the manifest, with this public key (64 hex)
bool verifyOffer(const agent::UpdateOffer& offer, const std::string& publicKeyHex);

struct Staged {
    agent::UpdateOffer offer;
    std::filesystem::path file;
};

class Updater {
public:
    enum class Outcome { Ignored, Available, Staged, Failed };

    Updater(std::filesystem::path updatesDir, std::optional<std::string> publicKey, net::ApiClient& api,
            net::HttpTransport& transport, events::Outbox& outbox);

    /// What to do with the heartbeat's offer. Staged means: restart to install.
    Outcome handle(const agent::UpdateOffer& offer);

    /// The release waiting in updatesDir, checked again (size, SHA-512, signature)
    std::optional<Staged> staged() const;

    /// Replaces `executable` with the staged release, keeping the old one as
    /// <executable>.old. Returns the version installed, or nullopt when nothing
    /// valid is staged. Throws when the swap itself fails (the old binary is
    /// put back first).
    std::optional<std::string> applyStaged(const std::filesystem::path& executable);

    /// Puts <executable>.old back. False when there is none.
    static bool rollback(const std::filesystem::path& executable);

    static constexpr std::uint64_t kMaxSize = 200ull * 1024 * 1024;

private:
    std::string download(const agent::UpdateOffer& offer);
    void fail(const agent::UpdateOffer& offer, const std::string& why);

    std::filesystem::path dir_;
    std::optional<std::string> publicKey_;
    net::ApiClient& api_;
    net::HttpTransport& transport_;
    events::Outbox& outbox_;
    std::set<std::string> announced_;  // update.available sent once per version
    std::set<std::string> refused_;    // a bad signature or hash: never tried again
    bool warnedNoKey_ = false;
};

}  // namespace hha::update
