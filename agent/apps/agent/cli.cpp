#include "cli.hpp"

#include "agent/config.hpp"
#include "agent/credentials.hpp"
#include "agent/enrollment.hpp"
#include "core/args.hpp"
#include "core/host.hpp"
#include "core/logging.hpp"
#include "core/paths.hpp"
#include "core/version.hpp"
#include "net/api_client.hpp"

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

Setting up:
  init --server URL        Write a starting config file (does not overwrite one)
  check-config             Read the config and list every problem in it
  enroll --code CODE       Pair this computer with a household, using a code from
         [--server URL]      the Computers page (the owner creates it)
         [--name NAME]
         [--force]           Replace existing credentials
  unenroll                 Forget the credentials (remove the computer on the dashboard too)

Checking:
  status                   Config, credentials, and whether the server accepts them
  version                  Version, git commit and platform
  help                     This text

Options:
  --config FILE            Config file (default: %s)
  --verbose                Log at debug level
)";

void printUsage() {
    std::printf(kUsage, paths::defaultConfigFile().string().c_str());
}

std::filesystem::path configPath(const Args& args) {
    return args.option("config").value_or(paths::defaultConfigFile().string());
}

agent::Config loadConfig(const Args& args) {
    return agent::Config::load(configPath(args));
}

std::unique_ptr<net::HttpTransport> transportFor(const agent::Config& config) {
    return net::makeSystemTransport({.userAgent = net::ApiClient::userAgent(), .caFile = config.caFile});
}

int version(const Args&) {
    std::cout << "home-helper-agent " << version::kVersion << " (" << version::kGitSha << ", "
              << version::platform() << ")\n";
    return 0;
}

int init(const Args& args) {
    const auto file = configPath(args);
    if (std::filesystem::exists(file)) {
        std::cerr << file.string() << " already exists; edit it, or delete it first.\n";
        return 1;
    }
    const auto server = args.option("server");
    if (!server) throw std::invalid_argument("init needs --server URL, e.g. --server https://home.example.org");
    paths::writeFileAtomically(file, agent::Config::example(*server).dump(2) + "\n");
    std::cout << "Wrote " << file.string() << "\n"
              << "Review the commands in it: they are the only things Home Helper can run on this computer.\n"
              << "Then: home-helper-agent enroll --code <code from the Computers page>\n";
    return 0;
}

int checkConfig(const Args& args) {
    const auto config = loadConfig(args);  // ConfigError lists the problems
    std::cout << "OK: " << config.file.string() << "\n"
              << "  data dir   " << config.dataDir.string() << "\n"
              << "  server     " << (config.serverUrl.empty() ? "(not set)" : config.serverUrl) << "\n"
              << "  commands   " << config.commands.size() << "\n";
    return 0;
}

int enroll(const Args& args) {
    auto config = loadConfig(args);
    const auto code = args.option("code");
    if (!code) throw std::invalid_argument("enroll needs --code, e.g. --code K7QM-2XPA");
    const auto server = args.option("server").value_or(config.serverUrl);
    if (server.empty()) throw std::invalid_argument("no server: pass --server URL or set server_url in the config");

    agent::CredentialStore store(config.credentialsFile());
    if (store.exists() && !args.flag("force")) {
        std::cerr << "This computer is already enrolled (" << store.file().string()
                  << "). Use --force to replace it.\n";
        return 1;
    }

    auto transport = transportFor(config);
    net::ApiClient client({.serverUrl = server, .allowInsecureHttp = config.allowInsecureHttp}, *transport);
    const auto hostname = host::hostname();
    const auto name = args.option("name").value_or(config.name);
    try {
        const auto credentials = agent::enroll(client, server, {.code = *code, .name = name, .hostname = hostname});
        store.save(credentials);
        std::cout << "Enrolled in \"" << credentials.household << "\" as agent " << credentials.agentId << ".\n"
                  << "Credentials: " << store.file().string() << "\n";
        return 0;
    } catch (const net::ApiError& e) {
        if (e.code() == "invalid_code") {
            std::cerr << "That code is wrong, used or expired. Create a new one on the Computers page.\n";
            return 1;
        }
        throw;
    }
}

int unenroll(const Args& args) {
    const auto config = loadConfig(args);
    agent::CredentialStore store(config.credentialsFile());
    if (!store.exists()) {
        std::cout << "Not enrolled.\n";
        return 0;
    }
    store.erase();
    std::cout << "Credentials removed. Remove the computer on the dashboard too, so its secret stops working.\n";
    return 0;
}

int status(const Args& args) {
    const auto config = loadConfig(args);
    std::cout << "home-helper-agent " << version::kVersion << " on " << host::hostname() << "\n"
              << "  config       " << config.file.string()
              << (std::filesystem::exists(config.file) ? "" : " (missing: defaults)") << "\n"
              << "  data dir     " << config.dataDir.string() << "\n";

    agent::CredentialStore store(config.credentialsFile());
    const auto credentials = store.load();
    if (!credentials) {
        std::cout << "  enrolled     no (home-helper-agent enroll --code …)\n";
        return 1;
    }
    std::cout << "  enrolled     " << credentials->agentId << " in \"" << credentials->household << "\"\n"
              << "  server       " << credentials->serverUrl << "\n";

    auto transport = transportFor(config);
    net::ApiClient client({.serverUrl = credentials->serverUrl, .allowInsecureHttp = config.allowInsecureHttp,
                           .timeout = std::chrono::seconds(10)},
                          *transport);
    client.setCredentials(*credentials);
    try {
        const auto me = client.get("me");
        std::cout << "  server says  OK, this is \"" << me.value("name", "") << "\"\n";
        return 0;
    } catch (const net::ApiError& e) {
        std::cout << "  server says  " << (e.code().empty() ? e.what() : e.code()) << "\n";
    } catch (const net::TransportError& e) {
        std::cout << "  server says  unreachable (" << e.what() << ")\n";
    }
    return 1;
}

}  // namespace

int run(const std::vector<std::string>& argv) {
    try {
        const auto args = Args::parse(argv, {"config", "server", "code", "name"});
        logging::install({.level = args.flag("verbose") ? "debug" : "warn"});

        const std::map<std::string, std::function<int(const Args&)>> commands = {
            {"version", version}, {"init", init},         {"check-config", checkConfig},
            {"enroll", enroll},   {"unenroll", unenroll}, {"status", status},
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
    } catch (const agent::ConfigError& e) {
        std::cerr << e.what() << "\n";
        return 2;
    } catch (const std::invalid_argument& e) {
        std::cerr << "Error: " << e.what() << "\n";
        return 2;
    } catch (const std::exception& e) {
        spdlog::critical("{}", e.what());
        return 1;
    }
}

}  // namespace hha::cli
