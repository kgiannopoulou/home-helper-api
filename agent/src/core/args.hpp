#pragma once

#include <map>
#include <optional>
#include <set>
#include <string>
#include <vector>

namespace hha {

/// `home-helper-agent [--config FILE] enroll --server URL --code ABCD-EFGH --verbose`
/// → command "enroll", options {config, server, code}, flags {verbose}.
/// Options that take a value are declared up front, so `--name "Mini PC"`
/// and `--name=Mini PC` both work and a stray value is an error, not a flag.
struct Args {
    std::string command;
    std::vector<std::string> positional;
    std::map<std::string, std::string> options;
    std::set<std::string> flags;

    std::optional<std::string> option(const std::string& name) const;
    bool flag(const std::string& name) const { return flags.contains(name); }

    /// Throws std::invalid_argument with a message for the person at the keyboard.
    static Args parse(const std::vector<std::string>& argv, const std::set<std::string>& valueOptions);
};

}  // namespace hha
