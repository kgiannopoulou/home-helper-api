#include "agent/inventory.hpp"

#include "crypto/crypto.hpp"
#include "net/api_client.hpp"

namespace hha::agent {

using nlohmann::json;

json buildInventory(const monitor::SystemInfo& system, const std::vector<audio::AudioDevice>& audio,
                    const json& commands) {
    json disks = json::array();
    for (const auto& d : system.disks) {
        disks.push_back({{"mount", d.mount}, {"total_bytes", d.totalBytes}, {"filesystem", d.filesystem}});
    }
    json devices = json::array();
    for (const auto& d : audio) devices.push_back(d.toJson());

    return {
        {"hostname", system.hostname},
        {"os", {{"name", system.osName}, {"version", system.osVersion}}},
        {"cpu", {{"model", system.cpuModel}, {"cores", system.cpuCores}}},
        {"memory_bytes", system.memoryBytes},
        {"disks", disks},
        {"audio", devices},
        {"commands", commands.is_array() ? commands : json::array()},
    };
}

std::string inventoryHash(const json& inventory) {
    // nlohmann::json keeps object keys sorted, so the same inventory always dumps the same
    return crypto::sha512Hex(net::dumpJson(inventory));
}

}  // namespace hha::agent
