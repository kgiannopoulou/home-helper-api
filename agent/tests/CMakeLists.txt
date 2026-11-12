add_executable(hha_tests
    core/ArgsTests.cpp
    core/SemVerTests.cpp
    core/StringsTests.cpp
    core/PathsTests.cpp
    crypto/CryptoTests.cpp
    net/SigningTests.cpp
    net/ApiClientTests.cpp
    agent/ConfigTests.cpp
    agent/EnrollmentTests.cpp
    agent/HeartbeatTests.cpp
    monitor/MonitorTests.cpp
    audio/AudioTests.cpp
    commands/CommandTests.cpp
)
target_include_directories(hha_tests PRIVATE ${CMAKE_CURRENT_SOURCE_DIR})
target_link_libraries(hha_tests PRIVATE hha_core hha_warnings Catch2::Catch2WithMain)
# The shared test vectors (tests/vectors/*.json) are read at run time
target_compile_definitions(hha_tests PRIVATE HHA_TEST_DATA_DIR="${CMAKE_CURRENT_SOURCE_DIR}")

include(Catch)
catch_discover_tests(hha_tests DISCOVERY_MODE PRE_TEST)

# A stand-in program for the command tests: prints the arguments it received
add_executable(hha_echo_args helpers/echo_args.cpp)
add_dependencies(hha_tests hha_echo_args)
target_compile_definitions(hha_tests PRIVATE HHA_ECHO_ARGS="$<TARGET_FILE:hha_echo_args>")
