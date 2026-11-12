#pragma once

#include "audio/audio_devices.hpp"
#include "monitor/metrics.hpp"

#include <nlohmann/json.hpp>

#include <string>
#include <vector>

namespace hha::agent {

/// What this computer has (docs/protocol.md#post-inventory): system, disks,
/// audio devices and the commands on its allowlist (key, description,
/// parameters: never the program paths).
nlohmann::json buildInventory(const monitor::SystemInfo& system, const std::vector<audio::AudioDevice>& audio,
                              const nlohmann::json& commands);

/// SHA-512 of the canonical JSON, sent with every heartbeat so the server can
/// tell when it is missing the latest inventory.
std::string inventoryHash(const nlohmann::json& inventory);

}  // namespace hha::agent
