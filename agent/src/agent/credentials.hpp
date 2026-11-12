#pragma once

#include "net/api_client.hpp"

#include <filesystem>
#include <optional>

namespace hha::agent {

/// What enrollment gave this computer, on disk (docs/design.md#security-decisions):
///
///   Windows  the secret is DPAPI-encrypted (machine scope, with an application
///            entropy), and the file's ACL allows only SYSTEM, Administrators and
///            the person who enrolled
///   Linux    the file is mode 0600, owned by the agent's user
///
/// Anyone who can read the file as that user can impersonate the computer; nobody
/// else can, and a copy of the disk is useless on another Windows machine.
class CredentialStore {
public:
    explicit CredentialStore(std::filesystem::path file) : file_(std::move(file)) {}

    std::optional<net::Credentials> load() const;
    void save(const net::Credentials& credentials) const;
    /// `home-helper-agent unenroll`: overwrites, then deletes
    void erase() const;
    bool exists() const { return std::filesystem::exists(file_); }

    const std::filesystem::path& file() const { return file_; }

private:
    std::filesystem::path file_;
};

}  // namespace hha::agent
