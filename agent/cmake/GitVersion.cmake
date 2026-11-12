# Run at every build (cmake -P), not only at configure time, so the git
# commit in `home-helper-agent version` and in every heartbeat is the one
# that was built. configure_file() only touches the header when the text
# changes, so an unchanged commit does not trigger a rebuild.
#
# In: SOURCE_DIR, TEMPLATE, OUTPUT, PROJECT_VERSION
set(HHA_GIT_SHA "unknown")
find_package(Git QUIET)
if(GIT_FOUND)
    execute_process(
        COMMAND ${GIT_EXECUTABLE} rev-parse --short=12 HEAD
        WORKING_DIRECTORY ${SOURCE_DIR}
        OUTPUT_VARIABLE sha
        OUTPUT_STRIP_TRAILING_WHITESPACE
        ERROR_QUIET
        RESULT_VARIABLE result)
    if(result EQUAL 0 AND sha)
        set(HHA_GIT_SHA ${sha})
    endif()
endif()
configure_file(${TEMPLATE} ${OUTPUT} @ONLY)
