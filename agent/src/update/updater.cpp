#include "update/updater.hpp"

#include "core/paths.hpp"
#include "core/semver.hpp"
#include "core/version.hpp"
#include "crypto/crypto.hpp"
#include "core/strings.hpp"

#include <spdlog/spdlog.h>

#include <nlohmann/json.hpp>

namespace hha::update {

using events::Event;
using events::Severity;
using nlohmann::json;

namespace {

const char* binaryName() {
#ifdef _WIN32
    return "home-helper-agent.exe";
#else
    return "home-helper-agent";
#endif
}

json offerJson(const agent::UpdateOffer& o) {
    return {{"version", o.version}, {"platform", o.platform}, {"url", o.url},
            {"sha512", o.sha512},   {"size", o.size},         {"signature", o.signature}};
}

}  // namespace

std::string manifest(const std::string& version, const std::string& platform, const std::string& sha512,
                     std::uint64_t size) {
    return "HH-AGENT-RELEASE-V1\n" + version + "\n" + platform + "\n" + sha512 + "\n" + std::to_string(size);
}

bool verifyOffer(const agent::UpdateOffer& offer, const std::string& publicKeyHex) {
    const auto key = crypto::publicKeyFromHex(publicKeyHex);
    const auto signature = crypto::signatureFromHex(offer.signature);
    if (!key || !signature) return false;
    return crypto::ed25519Verify(*signature, *key, manifest(offer.version, offer.platform, offer.sha512, offer.size));
}

Updater::Updater(std::filesystem::path updatesDir, std::optional<std::string> publicKey, net::ApiClient& api,
                 net::HttpTransport& transport, events::Outbox& outbox)
    : dir_(std::move(updatesDir)), publicKey_(std::move(publicKey)), api_(api), transport_(transport), outbox_(outbox) {}

void Updater::fail(const agent::UpdateOffer& offer, const std::string& why) {
    spdlog::error("Update to {} refused: {}", offer.version, why);
    refused_.insert(offer.version + "|" + offer.sha512);
    outbox_.add(Event::make("update.failed", Severity::Critical, "Update to " + offer.version + " refused: " + why,
                            {{"version", offer.version}, {"reason", why}}));
}

Updater::Outcome Updater::handle(const agent::UpdateOffer& offer) {
    if (!publicKey_) {
        if (!warnedNoKey_) {
            spdlog::info("An update to {} is offered, but updates are off: no updates.public_key in the config",
                         offer.version);
            warnedNoKey_ = true;
        }
        return Outcome::Ignored;
    }
    if (refused_.contains(offer.version + "|" + offer.sha512)) return Outcome::Ignored;

    const auto current = SemVer::parse(version::kVersion);
    const auto offered = SemVer::parse(offer.version);
    if (!offered) {
        fail(offer, "\"" + offer.version + "\" is not a version");
        return Outcome::Failed;
    }
    if (current && !(*current < *offered)) return Outcome::Ignored;  // never a downgrade
    if (offer.platform != version::platform()) {
        fail(offer, "it is for " + offer.platform + ", this is " + std::string(version::platform()));
        return Outcome::Failed;
    }
    if (offer.size == 0 || offer.size > kMaxSize) {
        fail(offer, "an implausible size");
        return Outcome::Failed;
    }
    // Before anything is downloaded: the key in this computer's config must have signed it
    if (!verifyOffer(offer, *publicKey_)) {
        fail(offer, "the signature does not match the release key in this computer's config");
        return Outcome::Failed;
    }

    if (!offer.install) {
        if (announced_.insert(offer.version).second) {
            spdlog::info("Update {} is available (signed); waiting for the owner to install it", offer.version);
            outbox_.add(Event::make("update.available", Severity::Info, "Version " + offer.version + " is available",
                                    {{"version", offer.version}, {"current", std::string(version::kVersion)}}));
        }
        return Outcome::Available;
    }

    if (const auto existing = staged(); existing && existing->offer.version == offer.version) {
        return Outcome::Staged;  // already downloaded: restart to install
    }

    std::string bytes;
    try {
        bytes = download(offer);
    } catch (const std::exception& e) {
        // The network, not the release: try again with the next heartbeat
        spdlog::warn("Downloading {} failed: {}", offer.version, e.what());
        return Outcome::Ignored;
    }
    if (bytes.size() != offer.size) {
        fail(offer, "the download is " + std::to_string(bytes.size()) + " bytes, not " + std::to_string(offer.size));
        return Outcome::Failed;
    }
    if (!crypto::constantTimeEquals(crypto::sha512Hex(bytes), strings::toLower(offer.sha512))) {
        fail(offer, "the download's SHA-512 does not match the signed one");
        return Outcome::Failed;
    }

    std::error_code ec;
    std::filesystem::remove_all(dir_ / "staged", ec);
    paths::writeFileAtomically(dir_ / "staged" / binaryName(), bytes);
    paths::writeFileAtomically(dir_ / "staged" / "release.json", offerJson(offer).dump(2));
    spdlog::info("Update {} downloaded and verified; restarting to install it", offer.version);
    outbox_.add(Event::make("update.staged", Severity::Info, "Version " + offer.version + " downloaded and verified",
                            {{"version", offer.version}}));
    return Outcome::Staged;
}

std::string Updater::download(const agent::UpdateOffer& offer) {
    // A path on the server: a signed request like any other. A full URL (a release
    // page): plain HTTPS; the signature and hash are what make it trustworthy.
    if (offer.url.find("://") == std::string::npos) {
        return api_.download(offer.url, offer.size + 1);
    }
    const auto url = net::Url::parse(offer.url);
    if (!url || !url->isHttps()) throw std::runtime_error("updates are only downloaded over https");
    net::HttpRequest request;
    request.url = offer.url;
    request.followRedirects = true;
    request.maxResponseBytes = offer.size + 1;
    request.timeout = std::chrono::minutes(5);
    const auto response = transport_.send(request);
    if (response.status != 200) throw std::runtime_error("HTTP " + std::to_string(response.status));
    return response.body;
}

std::optional<Staged> Updater::staged() const {
    const auto file = dir_ / "staged" / binaryName();
    const auto meta = dir_ / "staged" / "release.json";
    if (!std::filesystem::exists(file) || !std::filesystem::exists(meta) || !publicKey_) return std::nullopt;
    try {
        const auto j = json::parse(paths::readFile(meta));
        agent::UpdateOffer offer;
        offer.version = j.at("version").get<std::string>();
        offer.platform = j.at("platform").get<std::string>();
        offer.url = j.value("url", "");
        offer.sha512 = j.at("sha512").get<std::string>();
        offer.size = j.at("size").get<std::uint64_t>();
        offer.signature = j.at("signature").get<std::string>();
        // Never trust the disk: the same three checks as before the download
        if (offer.platform != version::platform() || std::filesystem::file_size(file) != offer.size ||
            crypto::sha512FileHex(file) != strings::toLower(offer.sha512) || !verifyOffer(offer, *publicKey_)) {
            spdlog::error("The staged update in {} failed verification; ignoring it", file.string());
            return std::nullopt;
        }
        return Staged{offer, file};
    } catch (const std::exception& e) {
        spdlog::error("The staged update is unreadable: {}", e.what());
        return std::nullopt;
    }
}

std::optional<std::string> Updater::applyStaged(const std::filesystem::path& executable) {
    const auto s = staged();
    if (!s) return std::nullopt;

    auto old = executable;
    old += ".old";
    std::error_code ec;
    std::filesystem::remove(old, ec);
    // A running .exe can't be overwritten, but it can be renamed
    std::filesystem::rename(executable, old);
    try {
        std::filesystem::copy_file(s->file, executable, std::filesystem::copy_options::overwrite_existing);
#ifndef _WIN32
        std::filesystem::permissions(executable, std::filesystem::perms::owner_all | std::filesystem::perms::group_read |
                                                     std::filesystem::perms::group_exec |
                                                     std::filesystem::perms::others_read |
                                                     std::filesystem::perms::others_exec);
#endif
    } catch (...) {
        std::filesystem::remove(executable, ec);
        std::filesystem::rename(old, executable, ec);
        throw;
    }
    std::filesystem::remove_all(dir_ / "staged", ec);
    spdlog::info("Installed {} (the previous version is kept as {})", s->offer.version, old.filename().string());
    return s->offer.version;
}

bool Updater::rollback(const std::filesystem::path& executable) {
    auto old = executable;
    old += ".old";
    if (!std::filesystem::exists(old)) return false;
    auto broken = executable;
    broken += ".rolled-back";
    std::error_code ec;
    std::filesystem::remove(broken, ec);
    std::filesystem::rename(executable, broken);
    std::filesystem::rename(old, executable);
    return true;
}

}  // namespace hha::update
