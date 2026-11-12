#include "cli.hpp"

#include "agent/agent.hpp"
#include "agent/config.hpp"
#include "agent/credentials.hpp"
#include "agent/enrollment.hpp"
#include "audio/audio_devices.hpp"
#include "commands/allowlist.hpp"
#include "commands/executor.hpp"
#include "core/time.hpp"
#include "core/args.hpp"
#include "core/host.hpp"
#include "core/logging.hpp"
#include "core/paths.hpp"
#include "core/version.hpp"
#include "monitor/system_monitor.hpp"
#include "net/api_client.hpp"
#include "shutdown.hpp"

#include <spdlog/spdlog.h>

#include <cstdio>
#include <exception>
#include <functional>
#include <iostream>
#include <map>
#include <stop_token>
#include <thread>

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

Running:
  run                      Connect and keep reporting, until Ctrl+C (the service does this)
      [--once]               One heartbeat, then exit

Checking:
  monitor                  Print this computer's readings, as the heartbeat sends them
  audio                    List microphones and speakers (they are never opened)
  commands                 The allowlist: the only commands Home Helper can run here
  run-command KEY [name=value…] [--confirm]
                           Try one allowlisted command locally, exactly as a request
                           from the dashboard would run (--confirm for a sensitive one)
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

int monitorCommand(const Args& args) {
    const auto config = loadConfig(args);
    auto monitor = monitor::makeSystemMonitor(config.disks);
    monitor->sample();
    std::this_thread::sleep_for(std::chrono::seconds(1));  // CPU usage is measured between two readings
    const auto info = monitor->info();
    nlohmann::json out = {
        {"metrics", monitor->sample().toJson()},
        {"system",
         {{"hostname", info.hostname},
          {"os", info.osName + " " + info.osVersion},
          {"cpu", info.cpuModel},
          {"cores", info.cpuCores},
          {"memory_bytes", info.memoryBytes}}},
    };
    std::cout << out.dump(2) << "\n";
    return 0;
}

int audioCommand(const Args&) {
    const auto devices = audio::makeAudioDiscovery()->list();
    if (devices.empty()) std::cout << "No audio devices found.\n";
    for (const auto& d : devices) {
        std::cout << (d.direction == audio::Direction::Input ? "mic      " : "speaker  ") << (d.isDefault ? "* " : "  ")
                  << d.name << "  [" << d.state << "]\n";
    }
    return 0;
}

commands::Allowlist loadAllowlist(const agent::Config& config) {
    return commands::Allowlist::fromJson(config.commands);  // ConfigError lists every problem
}

int commandsCommand(const Args& args) {
    const auto config = loadConfig(args);
    const auto list = loadAllowlist(config);
    if (list.empty()) {
        std::cout << "No commands: Home Helper can't run anything on this computer.\n";
        return 0;
    }
    for (const auto& c : list.entries()) {
        std::cout << c.key << (c.sensitive ? "  [sensitive: the owner confirms]" : "") << "\n"
                  << "    " << c.description << "\n"
                  << "    " << c.program.string();
        for (const auto& a : c.args) std::cout << " " << a;
        std::cout << "\n";
        for (const auto& p : c.params) std::cout << "    {" << p.name << "} must match " << p.pattern << "\n";
        std::cout << "    timeout " << c.timeout.count() << " s\n";
    }
    return 0;
}

int runCommandLocally(const Args& args) {
    const auto config = loadConfig(args);
    const auto list = loadAllowlist(config);
    if (args.positional.empty()) throw std::invalid_argument("run-command needs a KEY (see: home-helper-agent commands)");

    agent::CommandRequest request;
    request.id = "local-" + crypto::randomHex(4);
    request.key = args.positional[0];
    request.confirmed = args.flag("confirm");  // the person at the keyboard wrote the allowlist
    for (std::size_t i = 1; i < args.positional.size(); ++i) {
        const auto& kv = args.positional[i];
        const auto eq = kv.find('=');
        if (eq == std::string::npos) throw std::invalid_argument("parameters are name=value, got: " + kv);
        request.params[kv.substr(0, eq)] = kv.substr(eq + 1);
    }
    const auto result = commands::execute(list, request, commands::runProcess);
    std::cout << result.output;
    if (!result.output.empty() && result.output.back() != '\n') std::cout << "\n";
    std::cout << "-- " << result.status;
    if (result.status == "rejected") std::cout << ": " << result.reason;
    else std::cout << " (exit " << result.exitCode << ", " << result.durationMs << " ms" << (result.truncated ? ", output cut" : "") << ")";
    std::cout << "\n";
    return result.status == "succeeded" ? 0 : 1;
}

/// Everything `run` needs, built from the config and credentials
struct Runtime {
    agent::Config config;
    net::Credentials credentials;
    std::unique_ptr<net::HttpTransport> transport;
    std::unique_ptr<net::ApiClient> api;
    std::unique_ptr<monitor::SystemMonitor> monitor;
    std::unique_ptr<audio::AudioDiscovery> audio;
    commands::Allowlist allowlist;
};

Runtime prepare(const Args& args) {
    Runtime rt{loadConfig(args), {}, nullptr, nullptr, nullptr, nullptr, {}};
    logging::install({.level = args.flag("verbose") ? "debug" : rt.config.logLevel,
                      .file = rt.config.logToFile ? std::optional(rt.config.logFile()) : std::nullopt,
                      .console = true});
    const auto credentials = agent::CredentialStore(rt.config.credentialsFile()).load();
    if (!credentials) {
        throw std::invalid_argument("not enrolled yet: home-helper-agent enroll --code <code from the Computers page>");
    }
    rt.credentials = *credentials;
    rt.transport = transportFor(rt.config);
    rt.api = std::make_unique<net::ApiClient>(
        net::ApiClient::Options{.serverUrl = rt.credentials.serverUrl, .allowInsecureHttp = rt.config.allowInsecureHttp},
        *rt.transport);
    rt.api->setCredentials(rt.credentials);
    rt.monitor = monitor::makeSystemMonitor(rt.config.disks);
    if (rt.config.audioDiscovery) rt.audio = audio::makeAudioDiscovery();
    rt.allowlist = loadAllowlist(rt.config);
    spdlog::info("{} command(s) on the allowlist", rt.allowlist.entries().size());
    return rt;
}

int runCommand(const Args& args) {
    auto rt = prepare(args);
    agent::Agent agent(rt.config, {*rt.api, *rt.monitor, rt.audio.get(), &rt.allowlist, commands::runProcess});
    spdlog::info("home-helper-agent {} ({}) for \"{}\" at {}", version::kVersion, version::kGitSha,
                 rt.credentials.household, rt.credentials.serverUrl);

    if (args.flag("once")) {
        const auto reply = agent.beat();
        agent.finishCommands(std::chrono::minutes(5));
        std::cout << "Heartbeat OK: next in " << reply.heartbeat.count() << " s, " << reply.commands.size()
                  << " command(s) run\n";
        return 0;
    }

    std::stop_source stop;
    onShutdownRequest([&stop] {
        spdlog::info("Stopping…");
        stop.request_stop();
    });
    agent.run(stop.get_token());
    return stop.stop_requested() ? 0 : 3;  // 3: revoked, the service should not restart it
}

}  // namespace
int run(const std::vector<std::string>& argv) {
    try {
        const auto args = Args::parse(argv, {"config", "server", "code", "name"});
        logging::install({.level = args.flag("verbose") ? "debug" : "warn"});

        const std::map<std::string, std::function<int(const Args&)>> commands = {
            {"version", version}, {"init", init},         {"check-config", checkConfig},
            {"enroll", enroll},   {"unenroll", unenroll}, {"status", status},
            {"monitor", monitorCommand}, {"audio", audioCommand}, {"run", runCommand},
            {"commands", commandsCommand}, {"run-command", runCommandLocally},
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
