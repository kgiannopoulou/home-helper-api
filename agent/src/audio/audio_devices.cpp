#include "audio/audio_devices.hpp"

#include "core/strings.hpp"

#include <algorithm>
#include <charconv>
#include <map>
#include <set>
#include <tuple>

namespace hha::audio {

std::string_view toString(Direction direction) {
    return direction == Direction::Input ? "input" : "output";
}

nlohmann::json AudioDevice::toJson() const {
    return {{"id", id}, {"name", name}, {"direction", std::string(toString(direction))}, {"default", isDefault},
            {"state", state}};
}

AudioChanges diff(const std::vector<AudioDevice>& before, const std::vector<AudioDevice>& after) {
    const auto key = [](const AudioDevice& d) { return std::string(toString(d.direction)) + "|" + d.id; };
    std::set<std::string> was;
    std::set<std::string> is;
    for (const auto& d : before) was.insert(key(d));
    for (const auto& d : after) is.insert(key(d));

    AudioChanges changes;
    for (const auto& d : after) {
        if (!was.contains(key(d))) changes.added.push_back(d);
    }
    for (const auto& d : before) {
        if (!is.contains(key(d))) changes.removed.push_back(d);
    }
    return changes;
}

std::vector<AudioDevice> parseAsound(std::string_view cards, std::string_view pcm) {
    // /proc/asound/cards: " 0 [PCH            ]: HDA-Intel - HDA Intel PCH"
    std::map<int, std::string> cardNames;
    for (auto line : strings::split(cards, '\n')) {
        line = strings::trim(line);
        const auto bracket = line.find('[');
        const auto dash = line.find(" - ");
        if (bracket == std::string_view::npos || dash == std::string_view::npos) continue;
        int card = -1;
        const auto number = strings::trim(line.substr(0, bracket));
        if (std::from_chars(number.data(), number.data() + number.size(), card).ec != std::errc{}) continue;
        cardNames[card] = std::string(strings::trim(line.substr(dash + 3)));
    }

    std::vector<AudioDevice> out;
    for (auto line : strings::split(pcm, '\n')) {
        line = strings::trim(line);
        // "00-00: ALC892 Analog : ALC892 Analog : playback 1 : capture 1"
        if (line.size() < 6 || line[2] != '-' || line[5] != ':') continue;
        int card = 0;
        int device = 0;
        if (std::from_chars(line.data(), line.data() + 2, card).ec != std::errc{} ||
            std::from_chars(line.data() + 3, line.data() + 5, device).ec != std::errc{}) {
            continue;
        }
        const auto parts = strings::split(line.substr(6), ':');
        if (parts.empty()) continue;
        std::string name(strings::trim(parts[0]));
        if (const auto it = cardNames.find(card); it != cardNames.end() && name.find(it->second) == std::string::npos) {
            name = it->second + ", " + name;
        }
        for (std::size_t i = 2; i < parts.size(); ++i) {
            const auto part = strings::trim(parts[i]);
            const auto hw = "hw:" + std::to_string(card) + "," + std::to_string(device);
            if (strings::startsWith(part, "capture")) {
                out.push_back({hw + "/capture", name, Direction::Input, false, "active"});
            } else if (strings::startsWith(part, "playback")) {
                out.push_back({hw + "/playback", name, Direction::Output, false, "active"});
            }
        }
    }
    std::sort(out.begin(), out.end(), [](const AudioDevice& a, const AudioDevice& b) {
        return std::tie(a.direction, a.id) < std::tie(b.direction, b.id);
    });
    return out;
}

}  // namespace hha::audio
