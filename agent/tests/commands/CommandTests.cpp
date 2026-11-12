#include "commands/allowlist.hpp"
#include "commands/executor.hpp"
#include "commands/process.hpp"

#include "agent/agent.hpp"
#include "agent/config.hpp"
#include "core/time.hpp"
#include "fakes/FakeMonitor.hpp"
#include "fakes/FakeTransport.hpp"

#include <catch2/catch_test_macros.hpp>
#include <catch2/matchers/catch_matchers_string.hpp>

#include <atomic>
#include <thread>

#ifdef _WIN32
#include <windows.h>
#include <shellapi.h>
#endif

using namespace hha;
using namespace std::chrono_literals;
using Catch::Matchers::ContainsSubstring;
using nlohmann::json;

namespace {

const std::string kEcho = HHA_ECHO_ARGS;  // tests/helpers/echo_args.cpp

json echoEntry(json extra = json::object()) {
    json e = {{"key", "echo"},
              {"description", "Echo a host"},
              {"program", kEcho},
              {"args", {"--fixed", "{host}"}},
              {"params", {{"host", {{"pattern", "^[a-z0-9.]{1,40}$"}}}}}};
    e.update(extra);
    return e;
}

commands::Allowlist echoList(json extra = json::object()) {
    return commands::Allowlist::fromJson(json::array({echoEntry(std::move(extra))}));
}

agent::CommandRequest request(std::string key, json params, bool confirmed = false) {
    agent::CommandRequest r;
    r.id = "01jcmd0000000000000000000" + std::to_string(std::rand() % 10);
    r.key = std::move(key);
    r.params = std::move(params);
    r.confirmed = confirmed;
    return r;
}

std::string rejection(const commands::Allowlist& list, const agent::CommandRequest& r) {
    const auto d = list.resolve(r, std::chrono::system_clock::now());
    const auto* rej = std::get_if<commands::Rejection>(&d);
    return rej ? rej->reason : "";
}

std::vector<std::string> configProblems(const json& commands, bool checkPrograms = true) {
    try {
        commands::Allowlist::fromJson(commands, checkPrograms);
    } catch (const agent::ConfigError& e) {
        return e.problems();
    }
    return {};
}

}  // namespace

// --- The allowlist: what may be configured ---------------------------------

TEST_CASE("allowlist: shells and script interpreters are never allowed as programs") {
    for (const auto* program : {"C:\\Windows\\System32\\cmd.exe", "C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe",
                                "C:\\Program Files\\PowerShell\\7\\pwsh.exe", "/bin/bash", "/bin/sh", "/usr/bin/python3.12",
                                "/usr/bin/env", "/usr/bin/sudo", "C:\\Windows\\System32\\rundll32.exe",
                                "C:\\Windows\\System32\\wscript.exe", "/usr/bin/node", "C:\\Tools\\backup.bat",
                                "C:\\Tools\\cleanup.CMD", "C:\\Tools\\fix.ps1", "C:\\Tools\\job.vbs"}) {
        INFO(program);
        CHECK(commands::isInterpreter(program));
        const auto problems = configProblems(json::array({echoEntry({{"program", program}})}), false);
        REQUIRE(problems.size() == 1);
        CHECK_THAT(problems[0], ContainsSubstring("shell or script interpreter"));
    }
    CHECK_FALSE(commands::isInterpreter("C:\\Windows\\System32\\PING.EXE"));
    CHECK_FALSE(commands::isInterpreter("/usr/bin/systemctl"));
}

TEST_CASE("allowlist: programs are absolute paths to files that exist") {
    CHECK_THAT(configProblems(json::array({echoEntry({{"program", "ping"}})}))[0], ContainsSubstring("absolute path"));
    CHECK_THAT(configProblems(json::array({echoEntry({{"program", kEcho + ".missing"}})}))[0],
               ContainsSubstring("does not exist"));
}

TEST_CASE("allowlist: a parameter is a whole argument, declared and used") {
    // Glued to an option: could smuggle "x --other-option"? No, but it hides intent: refused
    CHECK_THAT(configProblems(json::array({echoEntry({{"args", {"--host={host}"}}})}))[0],
               ContainsSubstring("whole argument"));
    // Undeclared
    auto undeclared = configProblems(json::array({echoEntry({{"args", {"{host}", "{port}"}}})}));
    CHECK_THAT(undeclared[0], ContainsSubstring("{port} is not in params"));
    // Declared but unused
    CHECK_THAT(configProblems(json::array({echoEntry({{"args", {"--fixed"}}})}))[0], ContainsSubstring("not used"));
}

TEST_CASE("allowlist: every problem in the list at once") {
    const auto problems = configProblems(json::array({
        echoEntry({{"key", "Bad Key"}}),
        echoEntry(),
        echoEntry(),  // the same key twice
        echoEntry({{"key", "regex"}, {"params", {{"host", {{"pattern", "([unclosed"}}}}}}),
        echoEntry({{"key", "slow"}, {"timeout_seconds", 99999}, {"surprise", true}}),
    }));
    CHECK(problems.size() >= 5);
}

TEST_CASE("allowlist: the inventory sees keys and parameters, never programs") {
    const auto summary = echoList({{"sensitive", true}}).summary();
    CHECK(summary[0]["key"] == "echo");
    CHECK(summary[0]["sensitive"] == true);
    CHECK(summary[0]["params"][0]["name"] == "host");
    CHECK(summary.dump().find("echo_args") == std::string::npos);
}

// --- The allowlist: what may be requested ----------------------------------

TEST_CASE("resolve: the exact argv, with the value in one argument") {
    const auto list = echoList();
    const auto d = list.resolve(request("echo", {{"host", "router.lan"}}), std::chrono::system_clock::now());
    const auto* ok = std::get_if<commands::Resolved>(&d);
    REQUIRE(ok);
    CHECK(ok->argv == std::vector<std::string>{"--fixed", "router.lan"});
}

TEST_CASE("resolve: anything not on the list, or not exactly as declared, is refused") {
    const auto list = echoList();
    CHECK_THAT(rejection(list, request("format_disk", json::object())), ContainsSubstring("not on this computer's allowlist"));
    CHECK_THAT(rejection(list, request("echo", json::object())), ContainsSubstring("missing parameter"));
    CHECK_THAT(rejection(list, request("echo", {{"host", "a"}, {"extra", "b"}})), ContainsSubstring("unknown parameter"));
    CHECK_THAT(rejection(list, request("echo", {{"host", 42}})), ContainsSubstring("must be text"));
    CHECK_THAT(rejection(list, request("echo", "not an object")), ContainsSubstring("must be an object"));
}

TEST_CASE("resolve: injection attempts fail the pattern or the value checks") {
    const auto list = echoList();
    for (const auto* value : {"router.lan; rm -rf /", "router.lan && calc", "$(reboot)", "`id`", "a|b", "a\nb",
                              "../../etc/passwd", "router.lan "}) {
        INFO(value);
        CHECK_FALSE(rejection(list, request("echo", {{"host", value}})).empty());
    }
    CHECK_THAT(rejection(echoList({{"params", {{"host", {{"pattern", ".*"}}}}}}), request("echo", {{"host", "-rf"}})),
               ContainsSubstring("starts with '-'"));
    CHECK_THAT(rejection(echoList({{"params", {{"host", {{"pattern", ".*"}}}}}}), request("echo", {{"host", "a\tb"}})),
               ContainsSubstring("control character"));
    CHECK_THAT(rejection(echoList({{"params", {{"host", {{"pattern", ".*"}}}}}}),
                         request("echo", {{"host", std::string(201, 'a')}})),
               ContainsSubstring("longer than"));
}

TEST_CASE("resolve: a sensitive command needs the owner's confirmation, whatever the server says") {
    const auto list = echoList({{"sensitive", true}});
    auto r = request("echo", {{"host", "a"}});
    r.sensitive = false;  // the server claims it isn't: the local config decides
    CHECK_THAT(rejection(list, r), ContainsSubstring("needs the household owner's confirmation"));
    r.confirmed = true;
    CHECK(rejection(list, r).empty());
}

TEST_CASE("resolve: an expired request is refused") {
    const auto list = echoList();
    auto r = request("echo", {{"host", "a"}});
    r.expiresAt = time::iso8601(std::chrono::system_clock::now() - 1min);
    CHECK_THAT(rejection(list, r), ContainsSubstring("expired"));
    r.expiresAt = time::iso8601(std::chrono::system_clock::now() + 5min);
    CHECK(rejection(list, r).empty());
    r.expiresAt = "soon";
    CHECK_THAT(rejection(list, r), ContainsSubstring("unreadable"));
}

// --- Starting processes ------------------------------------------------------

TEST_CASE("process: Windows command-line quoting round-trips") {
    for (const std::wstring arg : {L"plain", L"with space", L"", L"quote\"inside", L"trailing\\", L"a\\\\b c\\",
                                   L"\\\"x", L"tab\there", L"&|<>^%!"}) {
        const auto quoted = commands::quoteWindowsArgument(arg);
#ifdef _WIN32
        int argc = 0;
        const auto line = L"prog.exe " + quoted;
        LPWSTR* argv = CommandLineToArgvW(line.c_str(), &argc);
        REQUIRE(argc == 2);
        CHECK(std::wstring(argv[1]) == arg);
        LocalFree(argv);
#else
        CHECK_FALSE(quoted.empty());
#endif
    }
}

TEST_CASE("process: arguments arrive exactly as given, with no shell to interpret them") {
    const std::vector<std::string> args = {"two words", "a&b|c;d", "$(id) `id` %PATH%", "quote\"d", "back\\slash\\",
                                           "\xce\xba\xce\xb1\xce\xbb\xce\xb7\xce\xbc\xce\xad\xcf\x81\xce\xb1"};
    const auto r = commands::runProcess(kEcho, args, 10s, 64 * 1024);
    CHECK(r.exitCode == 0);
    CHECK_FALSE(r.timedOut);
    std::string expected;
    for (const auto& a : args) expected += "[" + a + "]\n";
    std::string output = r.output;
    std::erase(output, '\r');
    CHECK(output == expected);
}

TEST_CASE("process: exit codes, stderr, and the output limit") {
    auto r = commands::runProcess(kEcho, {"--stderr", "--exit", "3"}, 10s, 1024);
    CHECK(r.exitCode == 3);
    CHECK_THAT(r.output, ContainsSubstring("to stderr"));

    r = commands::runProcess(kEcho, {"--spam", "5000"}, 10s, 1000);
    CHECK(r.output.size() == 1000);
    CHECK(r.truncated);
}

TEST_CASE("process: a program that runs too long is killed") {
    const auto r = commands::runProcess(kEcho, {"--sleep", "30000"}, 500ms, 1024);
    CHECK(r.timedOut);
    CHECK(r.duration < 10s);
}

TEST_CASE("process: a program that can't start is an error") {
    CHECK_THROWS(commands::runProcess(kEcho + ".missing", {}, 1s, 1024));
}

// --- The executor, the worker and the agent ---------------------------------

TEST_CASE("executor: outcomes are succeeded, failed, timed_out or rejected") {
    const auto list = echoList({{"timeout_seconds", 1}});
    const commands::ProcessRunner real = commands::runProcess;
    CHECK(commands::execute(list, request("echo", {{"host", "a"}}), real).status == "succeeded");
    CHECK(commands::execute(list, request("nope", json::object()), real).status == "rejected");

    commands::ProcessRunner failing = [](auto&&...) { return commands::ProcessResult{2, "boom", false, false, 5ms}; };
    const auto failed = commands::execute(list, request("echo", {{"host", "a"}}), failing);
    CHECK(failed.status == "failed");
    CHECK(failed.exitCode == 2);
    CHECK(failed.toJson()["output"] == "boom");

    commands::ProcessRunner slow = [](auto&&...) { return commands::ProcessResult{1, "", false, true, 1000ms}; };
    CHECK(commands::execute(list, request("echo", {{"host", "a"}}), slow).status == "timed_out");

    const auto rejected = commands::execute(list, request("nope", json::object()), real).toJson();
    CHECK(rejected["exit_code"].is_null());
    CHECK_FALSE(rejected["reason"].is_null());
}

TEST_CASE("worker: one at a time, each id once") {
    const auto list = echoList();
    std::atomic<int> runs{0};
    std::mutex m;
    std::vector<std::string> done;
    commands::CommandWorker worker(
        list,
        [&](auto&&...) {
            ++runs;
            std::this_thread::sleep_for(20ms);
            return commands::ProcessResult{0, "ok", false, false, 20ms};
        },
        [&](const commands::CommandResult& r) {
            std::lock_guard lock(m);
            done.push_back(r.id);
        });

    auto a = request("echo", {{"host", "a"}});
    a.id = "A";
    auto b = a;
    b.id = "B";
    CHECK(worker.submit(a));
    CHECK(worker.submit(b));
    CHECK_FALSE(worker.submit(a));  // the server sent it again before it had the result
    for (int i = 0; i < 300 && worker.busy() > 0; ++i) std::this_thread::sleep_for(10ms);
    std::lock_guard lock(m);
    CHECK(done == std::vector<std::string>{"A", "B"});
    CHECK(runs == 2);
}

TEST_CASE("agent: runs a command from the heartbeat and reports the result") {
    const crypto::Bytes secret(32, 0x33);
    std::mutex m;
    std::vector<json> results;
    bool offered = false;
    test::FakeTransport t([&](const net::HttpRequest& r) {
        if (r.url.ends_with("/result")) {
            std::lock_guard lock(m);
            results.push_back(json::parse(r.body));
            return test::signedResponse(secret, r, 204, "");
        }
        if (r.url.ends_with("/heartbeat") && !offered) {
            offered = true;
            return test::signedResponse(
                secret, r, 200,
                json{{"commands",
                      {{{"id", "C1"}, {"key", "echo"}, {"params", {{"host", "router.lan"}}}},
                       {{"id", "C2"}, {"key", "format_disk"}, {"params", json::object()}}}}}
                    .dump());
        }
        return test::signedResponse(secret, r, r.url.ends_with("/inventory") ? 204 : 200, r.url.ends_with("/inventory") ? "" : "{}");
    });
    net::ApiClient api({.serverUrl = "https://home.example.org"}, t);
    api.setCredentials({"https://home.example.org", "01jd0000000000000000000000", secret, "Home"});
    test::FakeMonitor monitor;
    const auto list = echoList();
    agent::Agent a(agent::Config::fromJson(json::object()), {api, monitor, nullptr, &list, commands::runProcess});

    a.beat();  // gets C1 and C2
    for (int i = 0; i < 300 && (a.commandsBusy() > 0 || a.pendingResults() < 1); ++i) std::this_thread::sleep_for(10ms);
    a.beat();  // sends what finished
    for (int i = 0; i < 100 && a.pendingResults() > 0; ++i) {
        std::this_thread::sleep_for(10ms);
        a.beat();
    }

    std::lock_guard lock(m);
    REQUIRE(results.size() == 2);
    std::map<std::string, json> byStatus;
    for (const auto& r : results) byStatus[r["status"].get<std::string>()] = r;
    REQUIRE(byStatus.contains("succeeded"));
    CHECK_THAT(byStatus["succeeded"]["output"].get<std::string>(), ContainsSubstring("[router.lan]"));
    REQUIRE(byStatus.contains("rejected"));
    CHECK_THAT(byStatus["rejected"]["reason"].get<std::string>(), ContainsSubstring("not on this computer's allowlist"));
}

TEST_CASE("agent: without an allowlist every command is refused") {
    const crypto::Bytes secret(32, 0x44);
    std::vector<json> results;
    test::FakeTransport t([&](const net::HttpRequest& r) {
        if (r.url.ends_with("/result")) results.push_back(json::parse(r.body));
        const bool hb = r.url.ends_with("/heartbeat");
        return test::signedResponse(secret, r, hb ? 200 : 204,
                                    hb ? json{{"commands", {{{"id", "C9"}, {"key", "echo"}}}}}.dump() : "");
    });
    net::ApiClient api({.serverUrl = "https://home.example.org"}, t);
    api.setCredentials({"https://home.example.org", "01jd0000000000000000000000", secret, "Home"});
    test::FakeMonitor monitor;
    agent::Agent a(agent::Config::fromJson(json::object()), {api, monitor});
    a.beat();
    REQUIRE(results.size() == 1);
    CHECK(results[0]["status"] == "rejected");
}
