# Home Helper Agent

A small C++20 program that connects the computers in your home to
[Home Helper](../README.md): it reports CPU, memory, disks, temperature and audio devices,
sends events, and runs a short, local allowlist of commands when a household member asks.

It lives in this repository (`agent/`) so the agent, the Laravel API it talks to and the
dashboard that shows it change together in one pull request. It is a self-contained CMake
project with no dependency on the rest of the repository, so it can be split out with
`git subtree split --prefix agent` at any time.

Phase 5 of Home Helper (days 57–70). Start with [docs/design.md](docs/design.md).
