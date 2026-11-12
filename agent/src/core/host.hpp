#pragma once

#include <string>

namespace hha::host {

/// The computer's name ("MINIPC", "raspberrypi")
std::string hostname();

/// "Windows", "Linux"
std::string osName();

/// "10.0.26300" on Windows (from the kernel, not the manifest-dependent API), the kernel release on Linux;
/// with the distribution's PRETTY_NAME on Linux when /etc/os-release has one
std::string osVersion();

}  // namespace hha::host
