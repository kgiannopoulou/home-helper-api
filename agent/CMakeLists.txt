cmake_minimum_required(VERSION 3.25)

project(HomeHelperAgent
    VERSION 0.5.0
    DESCRIPTION "Connects the computers in your home to Home Helper"
    LANGUAGES C CXX)

set(CMAKE_CXX_STANDARD 20)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS OFF)
set(CMAKE_EXPORT_COMPILE_COMMANDS ON)

# Executables at the top of the build dir: ./build/home-helper-agent
set(CMAKE_RUNTIME_OUTPUT_DIRECTORY ${CMAKE_BINARY_DIR})
foreach(config Debug Release RelWithDebInfo MinSizeRel)
    string(TOUPPER ${config} CONFIG_UPPER)
    set(CMAKE_RUNTIME_OUTPUT_DIRECTORY_${CONFIG_UPPER} ${CMAKE_BINARY_DIR})
endforeach()

option(HHA_BUILD_TESTS "Build the unit tests" ON)

# Sanitizers (GCC/Clang), e.g. -DHHA_SANITIZE=address,undefined
set(HHA_SANITIZE "" CACHE STRING "Comma-separated -fsanitize= list (empty = off)")
if(HHA_SANITIZE)
    if(MSVC)
        message(FATAL_ERROR "HHA_SANITIZE needs GCC or Clang")
    endif()
    add_compile_options(-fsanitize=${HHA_SANITIZE} -fno-omit-frame-pointer -fno-sanitize-recover=all)
    add_link_options(-fsanitize=${HHA_SANITIZE})
endif()

add_library(hha_warnings INTERFACE)
if(MSVC)
    target_compile_options(hha_warnings INTERFACE /W4 /permissive- /utf-8)
else()
    target_compile_options(hha_warnings INTERFACE -Wall -Wextra -Wpedantic -Wshadow)
endif()

include(cmake/Dependencies.cmake)

# --- Version: from project(VERSION) and git, baked into the binary ------------
set(HHA_GIT_SHA "unknown")
find_package(Git QUIET)
if(GIT_FOUND)
    execute_process(
        COMMAND ${GIT_EXECUTABLE} rev-parse --short=12 HEAD
        WORKING_DIRECTORY ${CMAKE_SOURCE_DIR}
        OUTPUT_VARIABLE HHA_GIT_SHA_OUT
        OUTPUT_STRIP_TRAILING_WHITESPACE
        ERROR_QUIET
        RESULT_VARIABLE HHA_GIT_RESULT)
    if(HHA_GIT_RESULT EQUAL 0 AND HHA_GIT_SHA_OUT)
        set(HHA_GIT_SHA ${HHA_GIT_SHA_OUT})
    endif()
endif()
configure_file(src/core/version.hpp.in ${CMAKE_BINARY_DIR}/generated/core/version.hpp @ONLY)

# --- The agent library: everything but main(), so the tests link it ---------
add_library(hha_core STATIC
    src/core/args.cpp
    src/core/logging.cpp
    src/core/semver.cpp
    src/core/strings.cpp
    src/core/paths.cpp
)
target_include_directories(hha_core PUBLIC src ${CMAKE_BINARY_DIR}/generated)
target_link_libraries(hha_core
    PUBLIC  spdlog::spdlog nlohmann_json::nlohmann_json
    PRIVATE hha_warnings)
if(WIN32)
    target_compile_definitions(hha_core PUBLIC WIN32_LEAN_AND_MEAN NOMINMAX UNICODE _UNICODE _WIN32_WINNT=0x0A00)
    target_link_libraries(hha_core PUBLIC shell32 ole32)
endif()

add_executable(home-helper-agent apps/agent/main.cpp apps/agent/cli.cpp)
target_link_libraries(home-helper-agent PRIVATE hha_core hha_warnings)

if(HHA_BUILD_TESTS)
    enable_testing()
    add_subdirectory(tests)
endif()

install(TARGETS home-helper-agent RUNTIME DESTINATION bin)
