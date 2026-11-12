#include "cli.hpp"

#include "core/args.hpp"
#include "core/logging.hpp"
#include "core/paths.hpp"
#include "core/version.hpp"

#include <spdlog/spdlog.h>

#include <cstdio>
#include <exception>
#include <functional>
#include <iostream>
#include <map>

namespace hha::cli {

namespace {

constexpr const char* kUsage = R"(home-helper-agent: connects this computer to Home Helper

Usage: home-helper-agent [--config FILE] [--verbose] <command> [options]

Commands:
  version                 Print the version, git commit and platform
  help                    This text

Options:
  --config FILE           Config file (default: %s)
  --verbose               Log at debug level
)";

void printUsage() {
    std::printf(kUsage, paths::defaultConfigFile().string().c_str());
}

int version(const Args&) {
    std::cout << "home-helper-agent " << version::kVersion << " (" << version::kGitSha << ", "
              << version::platform() << ")\n";
    return 0;
}

}  // namespace

int run(const std::vector<std::string>& argv) {
    try {
        const auto args = Args::parse(argv, {"config"});
        logging::install({.level = args.flag("verbose") ? "debug" : "info"});

        const std::map<std::string, std::function<int(const Args&)>> commands = {
            {"version", version},
        };

        if (args.command.empty() || args.command == "help" || args.flag("help")) {
            printUsage();
            return args.command.empty() ? 2 : 0;
        }
        const auto it = commands.find(args.command);
        if (it == commands.end()) {
            std::cerr << "Unknown command: " << args.command << "\n\n";
            printUsage();
            return 2;
        }
        return it->second(args);
    } catch (const std::invalid_argument& e) {
        std::cerr << "Error: " << e.what() << "\n";
        return 2;
    } catch (const std::exception& e) {
        spdlog::critical("{}", e.what());
        return 1;
    }
}

}  // namespace hha::cli
