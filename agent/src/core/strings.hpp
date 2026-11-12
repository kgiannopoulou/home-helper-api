#pragma once

#include <string>
#include <string_view>
#include <vector>

namespace hha::strings {

std::string_view trim(std::string_view text);

/// Empty parts are kept: split("a..b", '.') == {"a", "", "b"}.
std::vector<std::string_view> split(std::string_view text, char separator);

std::string toLower(std::string_view text);

bool startsWith(std::string_view text, std::string_view prefix);

/// True for printable text without control characters (tabs and newlines included).
bool isPrintable(std::string_view text);

#ifdef _WIN32
/// UTF-8 ⇄ UTF-16 for Windows APIs. Invalid input becomes U+FFFD.
std::wstring widen(std::string_view utf8);
std::string narrow(std::wstring_view utf16);
#endif

}  // namespace hha::strings
