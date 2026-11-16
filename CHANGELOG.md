# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [0.3.0] - 2026-11-16

### Added

- `mode` DSN parameter selecting the Swoole server mode: `process` (default)
  or `base`.
- README: "Static files: put nginx in front" — nginx `try_files` serves files
  from `public/` and forwards only the rest to Swoole; the built-in static
  handler is documented as a dev-only fallback.

### Changed

- **The server now runs in `SWOOLE_PROCESS` mode with `dispatch_mode=3` by
  default** (previously Swoole's own default, `SWOOLE_BASE`). In BASE mode a
  keep-alive connection stays with the worker that accepted it, so connections
  piled up on one blocking worker while the others idled — on symfony/demo with
  4 workers and 4 keep-alive connections throughput went from ~350 to ~1150
  rps. Set `mode=base` in the DSN to restore the previous behaviour.

### Fixed

- Multi-value response headers (`Link`, `Vary`, `WWW-Authenticate`...) lost
  every value but the last one: Swoole overwrites a repeated `header()` key, so
  all values are now passed in a single call.
- Hot-reload did not stop the server in `SWOOLE_PROCESS` mode: `stop()` called
  in the master only logged ERRNO 509. It now clears its own timer and calls
  `shutdown()`, which works in both modes.

## [0.2.0] - 2026-09-16

### Added

- `worker_memory_limit` DSN parameter: PHP `memory_limit` applied with
  `ini_set()` in every worker process before its kernel boots (php.ini
  shorthand, `-1` = unlimited). Workers otherwise inherit the CLI process'
  usually unlimited value, so a leak ends in an OOM kill; this turns it into a
  contained PHP fatal and a worker replaced by the manager.

## [0.1.0] - 2026-08-26

### Added

- Optional Prometheus metrics endpoint (`metrics.enabled`, default off),
  answered before the kernel like the health check: per-worker resident
  memory (`/proc` VmRSS/VmHWM) and PHP allocator memory, per-worker request
  and restart counters, master/manager RSS, server-wide connection/worker
  stats and `memory_limit`. Workers sample themselves into a shared
  `Swoole\Table`; any worker renders the scrape. Configurable `path`,
  `sample_interval` and metric `namespace`.

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
