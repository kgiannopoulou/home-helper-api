#include "cli.hpp"

#include <string>
#include <vector>

#ifdef _WIN32
#include "core/strings.hpp"

// wmain: arguments arrive as UTF-16 whatever the console code page is
int wmain(int argc, wchar_t** argv) {
    std::vector<std::string> args;
    for (int i = 1; i < argc; ++i) {
        args.push_back(hha::strings::narrow(argv[i]));
    }
    return hha::cli::run(args);
}
#else
int main(int argc, char** argv) {
    return hha::cli::run(std::vector<std::string>(argv + 1, argv + argc));
}
#endif
