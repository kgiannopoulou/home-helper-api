#include "agent/credentials.hpp"

#include "core/paths.hpp"

#include <nlohmann/json.hpp>

#include <stdexcept>

#ifdef _WIN32
#include <windows.h>
#include <aclapi.h>
#include <dpapi.h>
#include <sddl.h>
#else
#include <sys/stat.h>
#endif

namespace hha::agent {

using nlohmann::json;

namespace {

#ifdef _WIN32
constexpr char kEntropy[] = "home-helper-agent/credentials/v1";

DATA_BLOB blobOf(const std::uint8_t* data, std::size_t size) {
    return DATA_BLOB{static_cast<DWORD>(size), const_cast<BYTE*>(data)};
}

std::string protect(const crypto::Bytes& secret) {
    auto in = blobOf(secret.data(), secret.size());
    auto entropy = blobOf(reinterpret_cast<const std::uint8_t*>(kEntropy), sizeof(kEntropy) - 1);
    DATA_BLOB out{};
    // Machine scope: the service (SYSTEM) and the admin who enrolled both read it; the ACL keeps others out
    if (!CryptProtectData(&in, L"Home Helper agent", &entropy, nullptr, nullptr,
                          CRYPTPROTECT_LOCAL_MACHINE | CRYPTPROTECT_UI_FORBIDDEN, &out)) {
        throw std::runtime_error("CryptProtectData failed: " + std::to_string(GetLastError()));
    }
    const auto hex = crypto::toHex(std::span<const std::uint8_t>(out.pbData, out.cbData));
    LocalFree(out.pbData);
    return hex;
}

crypto::Bytes unprotect(const std::string& hex) {
    const auto bytes = crypto::fromHex(hex);
    if (!bytes) throw std::runtime_error("credentials: secret_dpapi is not hex");
    auto in = blobOf(bytes->data(), bytes->size());
    auto entropy = blobOf(reinterpret_cast<const std::uint8_t*>(kEntropy), sizeof(kEntropy) - 1);
    DATA_BLOB out{};
    if (!CryptUnprotectData(&in, nullptr, &entropy, nullptr, nullptr, CRYPTPROTECT_UI_FORBIDDEN, &out)) {
        throw std::runtime_error("credentials: cannot decrypt the secret on this computer (copied from another one?)");
    }
    crypto::Bytes secret(out.pbData, out.pbData + out.cbData);
    SecureZeroMemory(out.pbData, out.cbData);
    LocalFree(out.pbData);
    return secret;
}

/// Protected DACL: full control for SYSTEM, Administrators and the file's owner; nothing inherited
void restrictAccess(const std::filesystem::path& file) {
    PSECURITY_DESCRIPTOR sd = nullptr;
    if (!ConvertStringSecurityDescriptorToSecurityDescriptorW(L"D:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;FA;;;OW)",
                                                              SDDL_REVISION_1, &sd, nullptr)) {
        throw std::runtime_error("cannot build the credentials ACL");
    }
    BOOL present = FALSE;
    BOOL defaulted = FALSE;
    PACL dacl = nullptr;
    GetSecurityDescriptorDacl(sd, &present, &dacl, &defaulted);
    const auto result = SetNamedSecurityInfoW(const_cast<wchar_t*>(file.c_str()), SE_FILE_OBJECT,
                                              DACL_SECURITY_INFORMATION | PROTECTED_DACL_SECURITY_INFORMATION,
                                              nullptr, nullptr, dacl, nullptr);
    LocalFree(sd);
    if (result != ERROR_SUCCESS) {
        throw std::runtime_error("cannot restrict access to " + file.string() + ": " + std::to_string(result));
    }
}
#endif

}  // namespace

std::optional<net::Credentials> CredentialStore::load() const {
    if (!exists()) return std::nullopt;
    const auto j = json::parse(paths::readFile(file_), nullptr, false);
    if (j.is_discarded() || !j.is_object() || !j.contains("agent_id") || !j.contains("server_url")) {
        throw std::runtime_error(file_.string() + " is damaged: run `home-helper-agent enroll` again");
    }
    net::Credentials c;
    c.serverUrl = j["server_url"].get<std::string>();
    c.agentId = j["agent_id"].get<std::string>();
    c.household = j.value("household", "");
#ifdef _WIN32
    c.secret = unprotect(j.value("secret_dpapi", ""));
#else
    const auto secret = crypto::fromHex(j.value("secret", ""));
    if (!secret) throw std::runtime_error(file_.string() + ": the secret is not hex");
    c.secret = *secret;
#endif
    if (c.secret.size() != 32) throw std::runtime_error(file_.string() + ": the secret is not 32 bytes");
    return c;
}

void CredentialStore::save(const net::Credentials& c) const {
    json j = {{"server_url", c.serverUrl}, {"agent_id", c.agentId}, {"household", c.household}};
#ifdef _WIN32
    j["secret_dpapi"] = protect(c.secret);
#else
    j["secret"] = crypto::toHex(c.secret);
#endif
    std::filesystem::create_directories(file_.parent_path());
#ifndef _WIN32
    // Created 0600 before any secret is written into it
    const auto old = ::umask(077);
#endif
    paths::writeFileAtomically(file_, j.dump(2) + "\n");
#ifdef _WIN32
    restrictAccess(file_);
#else
    ::umask(old);
    std::filesystem::permissions(file_, std::filesystem::perms::owner_read | std::filesystem::perms::owner_write,
                                 std::filesystem::perm_options::replace);
#endif
}

void CredentialStore::erase() const {
    if (!exists()) return;
    const auto size = std::filesystem::file_size(file_);
    paths::writeFileAtomically(file_, std::string(static_cast<std::size_t>(size), '\0'));
    std::filesystem::remove(file_);
}

}  // namespace hha::agent
