#pragma once

#include <nlohmann/json.hpp>

#include <memory>
#include <string>
#include <string_view>
#include <vector>

/// Microphones and speakers (docs/design.md: the agent lists audio devices,
/// it never opens them: nothing is recorded or played).
namespace hha::audio {

enum class Direction { Input, Output };

struct AudioDevice {
    std::string id;     // stable across restarts: the endpoint id on Windows, "hw:<card>,<device>/<dir>" on Linux
    std::string name;   // "Microphone (Blue Yeti)"
    Direction direction = Direction::Input;
    bool isDefault = false;
    std::string state;  // active, unplugged, disabled, unknown

    nlohmann::json toJson() const;
    bool operator==(const AudioDevice&) const = default;
};

std::string_view toString(Direction direction);

/// This computer's devices: Core Audio (MMDevice) on Windows, /proc/asound on Linux.
class AudioDiscovery {
public:
    virtual ~AudioDiscovery() = default;
    virtual std::vector<AudioDevice> list() = 0;
};

std::unique_ptr<AudioDiscovery> makeAudioDiscovery();

struct AudioChanges {
    std::vector<AudioDevice> added;
    std::vector<AudioDevice> removed;
    bool empty() const { return added.empty() && removed.empty(); }
};

/// Devices that appeared or disappeared (by id and direction) between two listings;
/// a device that only changed state or name is neither.
AudioChanges diff(const std::vector<AudioDevice>& before, const std::vector<AudioDevice>& after);

/// /proc/asound/pcm: "00-00: ALC892 Analog : ALC892 Analog : playback 1 : capture 1", one
/// line per PCM device; `cards` (/proc/asound/cards) gives the card names.
std::vector<AudioDevice> parseAsound(std::string_view cards, std::string_view pcm);

}  // namespace hha::audio
