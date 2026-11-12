// Linux: ALSA's view in /proc/asound, which needs no library and no access to
// the sound server. PulseAudio/PipeWire names may differ; the hardware is the same.
#include "audio/audio_devices.hpp"

#include "core/paths.hpp"

namespace hha::audio {

namespace {

std::string readOr(const char* file) {
    try {
        return paths::readFile(file);
    } catch (const std::exception&) {
        return {};
    }
}

class LinuxAudio final : public AudioDiscovery {
public:
    std::vector<AudioDevice> list() override {
        return parseAsound(readOr("/proc/asound/cards"), readOr("/proc/asound/pcm"));
    }
};

}  // namespace

std::unique_ptr<AudioDiscovery> makeAudioDiscovery() {
    return std::make_unique<LinuxAudio>();
}

}  // namespace hha::audio
