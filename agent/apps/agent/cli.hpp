#pragma once

#include <string>
#include <vector>

namespace hha::cli {

/// The whole command line after the program name; returns the exit code.
int run(const std::vector<std::string>& argv);

}  // namespace hha::cli
