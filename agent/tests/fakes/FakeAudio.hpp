#pragma once

#include "audio/audio_devices.hpp"

#include <mutex>

namespace hha::test {

class FakeAudio final : public audio::AudioDiscovery {
public:
    std::vector<audio::AudioDevice> list() override {
        std::lock_guard lock(mutex);
        ++calls;
        return devices;
    }

    std::mutex mutex;
    std::vector<audio::AudioDevice> devices = {
        {"{mic-1}", "Microphone (USB Audio)", audio::Direction::Input, true, "active"},
        {"{spk-1}", "Speakers (Realtek)", audio::Direction::Output, true, "active"},
    };
    int calls = 0;
};

}  // namespace hha::test
