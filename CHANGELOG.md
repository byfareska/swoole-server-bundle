# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [0.0.1] - 2026-08-12

### Added

- Long-running Swoole HTTP server for Symfony (PHP-FPM-like worker model,
  coroutines disabled by design), configured with a single
  `swoole://host:port?workers=N&...` DSN.
- `byfareska:swoole:server:start` console command with a `--dsn` override.
- Response body emission strategies (`Bridge\Body\ResponseBodyEmitterInterface`):
  `BinaryFileResponse` via zero-copy `sendfile()` (ranges, `X-Sendfile`,
  `deleteFileAfterSend()`, temp-file fallback), `ChunkYieldingResponseInterface`,
  `StreamedResponse` (iterable or echoing callbacks) and a plain-content
  fallback; custom strategies are registered by implementing the interface
  (autoconfiguration).
- `Response::prepare()` before emission and HEAD responses without a body.
- Per-request worker state reset (`services_resetter` bridge, extensible via
  `Reset\WorkerResetterInterface`).
- Outside-the-kernel exception handling: replaceable error response factory,
  PSR-3/stderr logging and a manually collected profiler profile.
- Optional server-level health check endpoint (`health_check_path`).
- Hot reload in debug: mtime polling watcher stopping the server on change.
- Symfony Flex recipe under `recipe/`.
