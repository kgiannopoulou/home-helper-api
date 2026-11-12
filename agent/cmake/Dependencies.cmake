# Every dependency is pinned to a release tarball and its SHA-256, so a build
# never silently picks up different code. Monocypher is vendored in
# third_party/ (its tarball contains symlinks that do not extract on Windows).
include(FetchContent)

# --- spdlog: logging --------------------------------------------------------
# std::format instead of the bundled fmt (fmt's consteval format strings trip
# up Clang 18 in C++20 mode).
set(SPDLOG_USE_STD_FORMAT ON CACHE BOOL "" FORCE)
FetchContent_Declare(
    spdlog
    URL      https://github.com/gabime/spdlog/archive/refs/tags/v1.15.0.tar.gz
    URL_HASH SHA256=9962648c9b4f1a7bbc76fd8d9172555bad1871fdb14ff4f842ef87949682caa5
    DOWNLOAD_EXTRACT_TIMESTAMP TRUE
    SYSTEM
)

# --- nlohmann/json: the protocol and the config file ------------------------
FetchContent_Declare(
    nlohmann_json
    URL      https://github.com/nlohmann/json/releases/download/v3.11.3/json.tar.xz
    URL_HASH SHA256=d6c65aca6b1ed68e7a182f4757257b107ae403032760ed6ef121c9d55e81757d
    DOWNLOAD_EXTRACT_TIMESTAMP TRUE
    SYSTEM
)

FetchContent_MakeAvailable(spdlog nlohmann_json)

# --- Monocypher 4.0.2: SHA-512, HMAC-SHA-512, Ed25519 (vendored) -----------
add_library(monocypher STATIC
    ${CMAKE_SOURCE_DIR}/third_party/monocypher/monocypher.c
    ${CMAKE_SOURCE_DIR}/third_party/monocypher/monocypher-ed25519.c)
target_include_directories(monocypher SYSTEM PUBLIC ${CMAKE_SOURCE_DIR}/third_party/monocypher)
set_target_properties(monocypher PROPERTIES C_STANDARD 99 POSITION_INDEPENDENT_CODE ON)

# --- Catch2: tests ----------------------------------------------------------
if(HHA_BUILD_TESTS)
    FetchContent_Declare(
        Catch2
        URL      https://github.com/catchorg/Catch2/archive/refs/tags/v3.7.1.tar.gz
        URL_HASH SHA256=c991b247a1a0d7bb9c39aa35faf0fe9e19764213f28ffba3109388e62ee0269c
        DOWNLOAD_EXTRACT_TIMESTAMP TRUE
        SYSTEM
    )
    FetchContent_MakeAvailable(Catch2)
endif()
