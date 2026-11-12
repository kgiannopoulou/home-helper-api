// A stand-in program for the command tests: prints each argument on its own
// line between [ and ], so a test sees exactly what arrived. Options:
//   --sleep MS   wait first          --exit N   exit with N
//   --spam N     print N bytes of x  --stderr   write one line to stderr
#include <chrono>
#include <cstdio>
#include <cstdlib>
#include <string>
#include <thread>
#include <vector>

#ifdef _WIN32
#include <windows.h>
#include <string>

std::string narrow(const wchar_t* w) {
    const int n = WideCharToMultiByte(CP_UTF8, 0, w, -1, nullptr, 0, nullptr, nullptr);
    std::string s(static_cast<std::size_t>(n > 0 ? n - 1 : 0), '\0');
    if (n > 1) WideCharToMultiByte(CP_UTF8, 0, w, -1, s.data(), n, nullptr, nullptr);
    return s;
}

int wmain(int argc, wchar_t** wargv) {
    std::vector<std::string> args;
    for (int i = 1; i < argc; ++i) args.push_back(narrow(wargv[i]));
#else
int main(int argc, char** argv) {
    std::vector<std::string> args(argv + 1, argv + argc);
#endif
    int exitCode = 0;
    for (std::size_t i = 0; i < args.size(); ++i) {
        if (args[i] == "--sleep" && i + 1 < args.size()) {
            std::this_thread::sleep_for(std::chrono::milliseconds(std::atoi(args[++i].c_str())));
        } else if (args[i] == "--exit" && i + 1 < args.size()) {
            exitCode = std::atoi(args[++i].c_str());
        } else if (args[i] == "--spam" && i + 1 < args.size()) {
            const std::string x(static_cast<std::size_t>(std::atoi(args[++i].c_str())), 'x');
            std::fwrite(x.data(), 1, x.size(), stdout);
        } else if (args[i] == "--stderr") {
            std::fputs("to stderr\n", stderr);
        } else {
            std::printf("[%s]\n", args[i].c_str());
        }
        std::fflush(stdout);
    }
    return exitCode;
}
