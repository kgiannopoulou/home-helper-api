#include "core/logging.hpp"

#include <spdlog/sinks/rotating_file_sink.h>
#include <spdlog/sinks/stdout_color_sinks.h>
#include <spdlog/spdlog.h>

#include <stdexcept>
#include <vector>

namespace hha::logging {

namespace {

spdlog::level::level_enum parseLevel(std::string_view level) {
    const auto parsed = spdlog::level::from_str(std::string(level));
    // from_str returns off for anything it does not know
    if (parsed == spdlog::level::off && level != "off") {
        throw std::invalid_argument("unknown log level: " + std::string(level));
    }
    return parsed;
}

}  // namespace

void install(const Options& options) {
    std::vector<spdlog::sink_ptr> sinks;
    if (options.console) {
        sinks.push_back(std::make_shared<spdlog::sinks::stderr_color_sink_mt>());
    }
    if (options.file) {
        std::filesystem::create_directories(options.file->parent_path());
        sinks.push_back(std::make_shared<spdlog::sinks::rotating_file_sink_mt>(
            options.file->string(), 5 * 1024 * 1024, 3));
    }

    auto logger = std::make_shared<spdlog::logger>("agent", sinks.begin(), sinks.end());
    logger->set_pattern("[%Y-%m-%d %H:%M:%S.%e] [%l] %v");
    logger->set_level(parseLevel(options.level));
    logger->flush_on(spdlog::level::warn);
    spdlog::set_default_logger(logger);
    spdlog::flush_every(std::chrono::seconds(5));
}

bool isLevel(std::string_view level) {
    try {
        parseLevel(level);
        return true;
    } catch (const std::invalid_argument&) {
        return false;
    }
}

void setLevel(std::string_view level) {
    spdlog::set_level(parseLevel(level));
}

std::string redact(std::string_view secret) {
    if (secret.empty()) {
        return "(empty)";
    }
    const auto shown = secret.size() >= 16 ? std::size_t{4} : std::size_t{0};
    return std::string(secret.substr(0, shown)) + "…(" + std::to_string(secret.size()) + " chars)";
}

}  // namespace hha::logging
