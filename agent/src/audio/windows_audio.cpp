// Windows Core Audio: the same list as Sound settings, with full names and the
// default device for each direction. Only the device enumerator is used; no
// audio client is ever activated, so no stream is opened.
#include "audio/audio_devices.hpp"

#include "core/strings.hpp"

#include <spdlog/spdlog.h>

#include <windows.h>
#include <initguid.h>
#include <mmdeviceapi.h>
#include <functiondiscoverykeys_devpkey.h>

namespace hha::audio {

namespace {

template <typename T>
struct Com {
    T* p = nullptr;
    ~Com() {
        if (p) p->Release();
    }
    T** operator&() { return &p; }
    T* operator->() const { return p; }
    explicit operator bool() const { return p != nullptr; }
};

std::string stateName(DWORD state) {
    switch (state) {
        case DEVICE_STATE_ACTIVE: return "active";
        case DEVICE_STATE_UNPLUGGED: return "unplugged";
        case DEVICE_STATE_DISABLED: return "disabled";
        default: return "unknown";
    }
}

std::string idOf(IMMDevice* device) {
    LPWSTR id = nullptr;
    if (FAILED(device->GetId(&id))) return {};
    const auto out = strings::narrow(id);
    CoTaskMemFree(id);
    return out;
}

class WindowsAudio final : public AudioDiscovery {
public:
    std::vector<AudioDevice> list() override {
        const HRESULT init = CoInitializeEx(nullptr, COINIT_MULTITHREADED);
        std::vector<AudioDevice> out;
        {
            Com<IMMDeviceEnumerator> enumerator;
            if (FAILED(CoCreateInstance(__uuidof(MMDeviceEnumerator), nullptr, CLSCTX_INPROC_SERVER,
                                        __uuidof(IMMDeviceEnumerator), reinterpret_cast<void**>(&enumerator)))) {
                spdlog::warn("Core Audio is not available; no audio devices listed");
            } else {
                for (const auto [flow, direction] : {std::pair{eCapture, Direction::Input}, std::pair{eRender, Direction::Output}}) {
                    collect(enumerator.p, flow, direction, out);
                }
            }
        }
        if (SUCCEEDED(init)) CoUninitialize();
        return out;
    }

private:
    static void collect(IMMDeviceEnumerator* enumerator, EDataFlow flow, Direction direction,
                        std::vector<AudioDevice>& out) {
        std::string defaultId;
        {
            Com<IMMDevice> def;
            if (SUCCEEDED(enumerator->GetDefaultAudioEndpoint(flow, eConsole, &def))) defaultId = idOf(def.p);
        }
        Com<IMMDeviceCollection> devices;
        if (FAILED(enumerator->EnumAudioEndpoints(flow, DEVICE_STATE_ACTIVE | DEVICE_STATE_UNPLUGGED, &devices))) return;
        UINT count = 0;
        devices->GetCount(&count);
        for (UINT i = 0; i < count; ++i) {
            Com<IMMDevice> device;
            if (FAILED(devices->Item(i, &device))) continue;
            AudioDevice d;
            d.id = idOf(device.p);
            d.direction = direction;
            d.isDefault = !defaultId.empty() && d.id == defaultId;
            DWORD state = 0;
            device->GetState(&state);
            d.state = stateName(state);

            Com<IPropertyStore> props;
            if (SUCCEEDED(device->OpenPropertyStore(STGM_READ, &props))) {
                PROPVARIANT name;
                PropVariantInit(&name);
                if (SUCCEEDED(props->GetValue(PKEY_Device_FriendlyName, &name)) && name.vt == VT_LPWSTR) {
                    d.name = strings::narrow(name.pwszVal);
                }
                PropVariantClear(&name);
            }
            if (d.name.empty()) d.name = direction == Direction::Input ? "Microphone" : "Speakers";
            out.push_back(std::move(d));
        }
    }
};

}  // namespace

std::unique_ptr<AudioDiscovery> makeAudioDiscovery() {
    return std::make_unique<WindowsAudio>();
}

}  // namespace hha::audio
