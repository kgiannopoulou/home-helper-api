#include "commands/allowlist.hpp"

#include "agent/config.hpp"
#include "core/strings.hpp"
#include "core/time.hpp"

#include <algorithm>
#include <cctype>
#include <regex>
#include <set>

namespace hha::commands {

using nlohmann::json;

namespace {

const std::regex kKey("^[a-z][a-z0-9_]{0,49}$");
const std::regex kParamName("^[a-z][a-z0-9_]{0,29}$");
const std::regex kPlaceholder("^\\{([a-z][a-z0-9_]{0,29})\\}$");

constexpr std::size_t kMaxValueLength = 200;

}  // namespace

bool isInterpreter(const std::filesystem::path& program) {
    static const std::set<std::string> names = {
        "cmd",     "command",  "powershell", "pwsh",    "bash",   "sh",     "dash",   "zsh",    "ksh",
        "csh",     "tcsh",     "fish",       "busybox", "env",    "python", "python3", "py",    "pythonw",
        "perl",    "ruby",     "node",       "nodejs",  "php",    "lua",    "wscript", "cscript", "mshta",
        "rundll32", "regsvr32", "msiexec",   "sudo",    "su",     "doas",   "xargs",  "nohup",  "osascript",
    };
    // Scripts that Windows hands to an interpreter: CreateProcess runs a .bat or .cmd
    // through cmd.exe, which re-parses the arguments (the "BatBadBut" class of bugs)
    static const std::set<std::string> scripts = {".bat", ".cmd", ".ps1", ".vbs", ".vbe", ".js",
                                                  ".jse", ".wsf", ".wsh", ".hta", ".msc", ".lnk"};
    // The file name, split on both separators: std::filesystem only knows '/' on
    // Linux, and the same rules must hold for a Windows path checked anywhere
    const auto text = program.string();
    const auto slash = text.find_last_of("/\\");
    auto name = strings::toLower(slash == std::string::npos ? text : text.substr(slash + 1));
    if (const auto dot = name.rfind('.'); dot != std::string::npos && scripts.contains(name.substr(dot))) return true;

    // Without its extension: "cmd.exe" and "bash" alike
    for (const auto* ext : {".exe", ".com", ".bat", ".cmd"}) {
        if (name.ends_with(ext)) name.resize(name.size() - 4);
    }
    if (names.contains(name)) return true;
    // python3.12, php8.4, perl5…
    while (!name.empty() && (std::isdigit(static_cast<unsigned char>(name.back())) || name.back() == '.')) name.pop_back();
    return names.contains(name);
}

std::optional<std::string> checkValue(const std::string& value) {
    if (value.empty()) return "is empty";
    if (value.size() > kMaxValueLength) return "is longer than " + std::to_string(kMaxValueLength) + " characters";
    // "-rf" would be read as an option. ('/s' style Windows switches are up to each pattern to refuse.)
    if (value.front() == '-') return "starts with '-' (an option, not a value)";
    for (const unsigned char c : value) {
        if (c < 0x20 || c == 0x7f) return "contains a control character";
    }
    return std::nullopt;
}

Allowlist Allowlist::fromJson(const json& commands, bool checkPrograms) {
    std::vector<std::string> problems;
    Allowlist list;
    std::set<std::string> keys;

    if (!commands.is_array()) throw agent::ConfigError({"commands: must be a list"});

    for (std::size_t i = 0; i < commands.size(); ++i) {
        const auto& c = commands[i];
        const auto where = "commands[" + std::to_string(i) + "]";
        if (!c.is_object()) {
            problems.push_back(where + ": must be an object");
            continue;
        }
        static const std::set<std::string> known = {"key", "description", "program", "args", "params",
                                                    "sensitive", "timeout_seconds", "max_output_kb"};
        for (const auto& [k, _] : c.items()) {
            if (!known.contains(k)) problems.push_back(where + "." + k + ": unknown setting");
        }

        CommandSpec spec;
        spec.key = c.value("key", "");
        const auto at = spec.key.empty() ? where : "command \"" + spec.key + "\"";
        if (!std::regex_match(spec.key, kKey)) {
            problems.push_back(where + ".key: lower case letters, digits and _ (like ping_router)");
        } else if (!keys.insert(spec.key).second) {
            problems.push_back(at + ": the key is used twice");
        }
        spec.description = c.value("description", "");
        if (spec.description.empty() || spec.description.size() > 200) {
            problems.push_back(at + ".description: required, at most 200 characters");
        }

        spec.program = std::filesystem::path(c.value("program", "")).make_preferred();
        if (isInterpreter(spec.program)) {
            problems.push_back(at + ".program: " + spec.program.filename().string() +
                               " is a shell or script interpreter; parameters would become code");
        } else if (!spec.program.is_absolute()) {
            problems.push_back(at + ".program: must be an absolute path (no PATH lookup)");
        } else if (checkPrograms && !std::filesystem::is_regular_file(spec.program)) {
            problems.push_back(at + ".program: " + spec.program.string() + " does not exist");
        }

        if (c.contains("params")) {
            if (!c["params"].is_object()) {
                problems.push_back(at + ".params: must be an object of name: {pattern, description}");
            } else {
                for (const auto& [name, p] : c["params"].items()) {
                    ParamSpec param;
                    param.name = name;
                    if (!std::regex_match(name, kParamName)) {
                        problems.push_back(at + ".params." + name + ": lower case letters, digits and _");
                        continue;
                    }
                    param.pattern = p.is_object() ? p.value("pattern", "") : "";
                    param.description = p.is_object() ? p.value("description", "") : "";
                    if (param.pattern.empty()) {
                        problems.push_back(at + ".params." + name + ".pattern: required (a regular expression)");
                        continue;
                    }
                    try {
                        param.regex = std::regex(param.pattern, std::regex::ECMAScript);
                    } catch (const std::regex_error&) {
                        problems.push_back(at + ".params." + name + ".pattern: not a valid regular expression");
                        continue;
                    }
                    spec.params.push_back(std::move(param));
                }
            }
        }

        std::set<std::string> used;
        if (c.contains("args") && !c["args"].is_array()) {
            problems.push_back(at + ".args: must be a list of strings");
        } else if (c.contains("args")) {
            for (const auto& a : c["args"]) {
                if (!a.is_string()) {
                    problems.push_back(at + ".args: must be a list of strings");
                    break;
                }
                const auto arg = a.get<std::string>();
                std::smatch m;
                if (std::regex_match(arg, m, kPlaceholder)) {
                    const auto name = m[1].str();
                    const bool declared = std::any_of(spec.params.begin(), spec.params.end(),
                                                      [&](const ParamSpec& p) { return p.name == name; });
                    if (!declared) problems.push_back(at + ".args: {" + name + "} is not in params");
                    used.insert(name);
                } else if (arg.find('{') != std::string::npos || arg.find('}') != std::string::npos) {
                    problems.push_back(at + ".args: \"" + arg +
                                       "\": a parameter must be a whole argument, like \"{host}\"");
                }
                spec.args.push_back(arg);
            }
        }
        for (const auto& p : spec.params) {
            if (!used.contains(p.name)) problems.push_back(at + ".params." + p.name + ": not used in args");
        }

        spec.sensitive = c.value("sensitive", false);
        const auto timeout = c.value("timeout_seconds", 30);
        if (timeout < 1 || timeout > 3600) problems.push_back(at + ".timeout_seconds: between 1 and 3600");
        spec.timeout = std::chrono::seconds(timeout);
        const auto outputKb = c.value("max_output_kb", 16);
        if (outputKb < 1 || outputKb > 1024) problems.push_back(at + ".max_output_kb: between 1 and 1024");
        spec.maxOutputBytes = static_cast<std::size_t>(outputKb) * 1024;

        list.entries_.push_back(std::move(spec));
    }

    if (!problems.empty()) throw agent::ConfigError(problems);
    return list;
}

const CommandSpec* Allowlist::find(const std::string& key) const {
    for (const auto& e : entries_) {
        if (e.key == key) return &e;
    }
    return nullptr;
}

std::variant<Resolved, Rejection> Allowlist::resolve(const agent::CommandRequest& request,
                                                     std::chrono::system_clock::time_point now) const {
    const auto* spec = find(request.key);
    if (!spec) return Rejection{"\"" + request.key + "\" is not on this computer's allowlist"};

    if (!request.expiresAt.empty()) {
        const auto expires = time::parseIso8601(request.expiresAt);
        if (!expires) return Rejection{"the request has an unreadable expiry"};
        if (*expires < now) return Rejection{"the request expired before it arrived"};
    }

    // The local flag decides, whatever the server says
    if (spec->sensitive && !request.confirmed) {
        return Rejection{"\"" + spec->key + "\" is sensitive and needs the household owner's confirmation"};
    }

    if (!request.params.is_object()) return Rejection{"params must be an object"};
    for (const auto& [name, _] : request.params.items()) {
        const bool known = std::any_of(spec->params.begin(), spec->params.end(),
                                       [&](const ParamSpec& p) { return p.name == name; });
        if (!known) return Rejection{"unknown parameter \"" + name + "\""};
    }

    std::map<std::string, std::string> values;
    for (const auto& p : spec->params) {
        if (!request.params.contains(p.name)) return Rejection{"missing parameter \"" + p.name + "\""};
        const auto& v = request.params[p.name];
        if (!v.is_string()) return Rejection{"parameter \"" + p.name + "\" must be text"};
        const auto value = v.get<std::string>();
        if (const auto problem = checkValue(value)) return Rejection{"parameter \"" + p.name + "\" " + *problem};
        if (!std::regex_match(value, p.regex)) {
            return Rejection{"parameter \"" + p.name + "\" does not match " + p.pattern};
        }
        values[p.name] = value;
    }

    Resolved resolved;
    resolved.spec = spec;
    for (const auto& arg : spec->args) {
        std::smatch m;
        if (std::regex_match(arg, m, kPlaceholder)) {
            resolved.argv.push_back(values.at(m[1].str()));
        } else {
            resolved.argv.push_back(arg);
        }
    }
    return resolved;
}

json Allowlist::summary() const {
    json out = json::array();
    for (const auto& e : entries_) {
        json params = json::array();
        for (const auto& p : e.params) {
            params.push_back({{"name", p.name}, {"description", p.description}, {"pattern", p.pattern}});
        }
        out.push_back({{"key", e.key}, {"description", e.description}, {"sensitive", e.sensitive}, {"params", params}});
    }
    return out;
}

}  // namespace hha::commands
