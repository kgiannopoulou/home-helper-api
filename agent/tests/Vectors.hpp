#pragma once

#include "core/paths.hpp"

#include <nlohmann/json.hpp>

namespace hha::test {

/// tests/vectors/signing.json: generated with PHP's hash() and sodium, and read
/// by the Laravel tests too, so both sides agree byte for byte.
inline const nlohmann::json& signingVectors() {
    static const auto vectors =
        nlohmann::json::parse(paths::readFile(std::filesystem::path(HHA_TEST_DATA_DIR) / "vectors" / "signing.json"));
    return vectors;
}

}  // namespace hha::test
