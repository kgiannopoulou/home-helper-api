#include "core/args.hpp"

#include <stdexcept>

namespace hha {

std::optional<std::string> Args::option(const std::string& name) const {
    if (const auto it = options.find(name); it != options.end()) {
        return it->second;
    }
    return std::nullopt;
}

Args Args::parse(const std::vector<std::string>& argv, const std::set<std::string>& valueOptions) {
    Args args;
    bool onlyPositional = false;
    for (std::size_t i = 0; i < argv.size(); ++i) {
        const std::string& arg = argv[i];
        if (!onlyPositional && arg == "--") {
            onlyPositional = true;
            continue;
        }
        if (!onlyPositional && arg.size() > 2 && arg.rfind("--", 0) == 0) {
            std::string name = arg.substr(2);
            std::optional<std::string> value;
            if (const auto eq = name.find('='); eq != std::string::npos) {
                value = name.substr(eq + 1);
                name = name.substr(0, eq);
            }
            if (valueOptions.contains(name)) {
                if (!value) {
                    if (i + 1 >= argv.size()) {
                        throw std::invalid_argument("--" + name + " needs a value");
                    }
                    value = argv[++i];
                }
                args.options[name] = *value;
            } else {
                if (value) {
                    throw std::invalid_argument("--" + name + " does not take a value");
                }
                args.flags.insert(name);
            }
            continue;
        }
        if (args.command.empty()) {
            args.command = arg;
        } else {
            args.positional.push_back(arg);
        }
    }
    return args;
}

}  // namespace hha
