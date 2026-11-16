# byfareska/swoole-server-bundle

A long-running Swoole HTTP server for Symfony 8+ applications (PHP 8.5+).

Works like PHP-FPM: each worker is a separate process with its own Symfony
kernel and handles **one request at a time** (`enable_coroutine=false`).
Concurrency comes from the number of workers. Coroutines are disabled on
purpose — a worker has a single kernel, i.e. one Doctrine connection and one
identity map, so overlapping requests would share state (e.g. the PDO error
"Cannot execute queries while other unbuffered queries are active").

## Requirements

- PHP >= 8.5 with the `swoole` extension
- Symfony >= 8.0

## Installation

```bash
composer require byfareska/swoole-server-bundle
```

Registration in `config/bundles.php` (without Flex):

```php
return [
    // ...
    Byfareska\SwooleServer\SwooleServerBundle::class => ['all' => true],
];
```

## Configuration

The whole server is configured with a single DSN — preferably from an env var:

```dotenv
# .env
SWOOLE_SERVER_DSN=swoole://0.0.0.0:8000?workers=4&package_max_length=67108864
```

```yaml
# config/packages/byfareska_swoole_server.yaml
byfareska_swoole_server:
    dsn: '%env(SWOOLE_SERVER_DSN)%'

    # optional:
    # kernel_class: App\Kernel   # null = the application kernel class (detected automatically)
    # health_check_path: /healthz # plain-text 200 "ok" answered before the kernel; null = disabled
    # metrics:
    #     enabled: true          # Prometheus text endpoint answered before the kernel (default: false)
    #     path: /metrics
    #     sample_interval: 5000  # ms between a worker's self-samples
    #     namespace: swoole      # metric name prefix
    hot_reload:
        # enabled: null          # null = enabled only when kernel.debug
        watch_dirs:
            - '%kernel.project_dir%/src'
            - '%kernel.project_dir%/config'
            - '%kernel.project_dir%/templates'
        interval: 1000           # ms between scans
        extensions: [php, twig, yaml]
```

### DSN format

```
swoole://HOST:PORT?workers=N&<any_swoole_options>
```

- `workers` — number of workers; `0` or absent = `swoole_cpu_num()`
  (`worker_num` is rejected with a hint — use `workers`)
- `worker_memory_limit` — PHP `memory_limit` applied in every worker process
  (`512M`, `2G`, `536870912`, `-1` = unlimited); absent = whatever the
  `bin/console` process has, which in the CLI SAPI is usually `-1`. See
  [Worker memory limit](#worker-memory-limit) below.
- `mode` — Swoole server mode: `process` (default) or `base`. See
  [Server mode](#server-mode) below.
- **every other query parameter** goes 1:1 to `$server->set()` — e.g.
  `package_max_length=134217728`, `log_level=warning` (level names are mapped
  to the `SWOOLE_LOG_*` constants), `http_compression=true`
- `true/false/on/off` values are cast to bool, numbers to int

Default server settings (overridable via the DSN):

| option | value | why |
| --- | --- | --- |
| `enable_coroutine` | `false` | requests within a worker are serialized — they share one kernel |
| `dispatch_mode` | `3` (`process` mode only) | every request goes to an idle worker — see [Server mode](#server-mode) |
| `http_compression` | `false` | — |
| `max_request` | `0` | no worker recycling (long-lived connections) |
| `package_max_length` | 64 MB | the upload limit is enforced by Swoole, not PHP ini |
| `output_buffer_size` | 64 MB | Swoole's own default (2 MB) aborts larger buffered responses with a warning |
| `log_level` | `SWOOLE_LOG_INFO` | — |

### Server mode

Workers are blocking — one request at a time — so what matters is which
worker gets the next request.

- **`process` (default, `SWOOLE_PROCESS`)** — the master's reactor threads
  accept connections and hand every request to a worker; with the default
  `dispatch_mode=3` that is an **idle** worker, the way PHP-FPM hands requests
  to free children.
- **`base` (`SWOOLE_BASE`, Swoole's own default)** — each worker accepts
  connections itself and a keep-alive connection stays with the worker that
  accepted it. A burst of connections tends to land on one worker while the
  others idle, and a slow request blocks every other request queued on its
  connection's worker. Behind a reverse proxy that keeps a small pool of
  keep-alive connections (nginx `keepalive`) most workers may never see
  traffic. It saves one IPC hop per request (~50 µs), so it can make sense
  for a single worker or short-lived connections.

Measured on symfony/demo (4 workers, keep-alive, `/en/blog/`):

| | 4 connections | 8 connections |
| --- | --- | --- |
| `mode=base` | 350–970 rps, p50 3–10.6 ms (varies with how connections land) | 665–1000 rps |
| `mode=process` (`dispatch_mode=3`) | ~1150 rps, p50 3.2 ms | ~1200 rps |

`dispatch_mode` stays overridable through the DSN (e.g. `dispatch_mode=2` for
fd-based stickiness). When every worker is busy, Swoole queues the request on
a busy worker rather than in a shared queue, so a request can wait behind a
slow one — size `workers` for your peak concurrency of slow requests.

### Worker memory limit

Workers are never recycled (`max_request=0`) and inherit the CLI process'
`memory_limit`, which is normally `-1` — so a leaking worker grows until the
kernel's OOM killer takes it: no application log entry, and possibly other
processes killed along with it. `worker_memory_limit` puts a ceiling on that:

```dotenv
SWOOLE_SERVER_DSN=swoole://0.0.0.0:8000?workers=4&worker_memory_limit=512M
```

- applied with `ini_set()` in the `workerStart` callback, before the worker
  boots its kernel — so the boot itself is covered too
- workers only; the master and manager processes keep the CLI limit
- it is a **hard** backstop, not graceful recycling: crossing it is a PHP fatal
  error, so the in-flight request dies (the client gets no response) and the
  manager forks a replacement worker. Size it above your real peak — it should
  catch a leak, not ordinary traffic.
- with `metrics` enabled the limit shows up as `php_memory_limit_bytes` and the
  replacements are counted by `worker_restarts_total`, which makes the
  `worker_memory_rss_bytes / php_memory_limit_bytes` alert below meaningful

## Usage

```bash
bin/console byfareska:swoole:server:start
# or with a DSN override:
bin/console byfareska:swoole:server:start --dsn='swoole://127.0.0.1:9501?workers=2'
```

## Health check

Set `health_check_path` (e.g. `/healthz`) to get a plain-text `200 ok`
answered directly by the server, before the kernel — it reports liveness of
the server process and responds even while workers are still booting. Handy
for docker/k8s health checks:

```yaml
healthcheck:
    test: ["CMD", "curl", "-fs", "http://localhost:8000/healthz"]
```

## Metrics (Prometheus)

Long-running workers never get recycled (`max_request=0`), so a slow leak
grows silently until the OOM killer restarts the process — and with N
workers you cannot tell "one worker leaks" from "all of them grow" without
per-process numbers. Enable `metrics` to get a Prometheus text endpoint
answered directly by the server, before the kernel (like the health check):

```yaml
byfareska_swoole_server:
    metrics:
        enabled: true
```

```
# HELP swoole_worker_memory_rss_bytes Resident set size (VmRSS) of the worker process.
# TYPE swoole_worker_memory_rss_bytes gauge
swoole_worker_memory_rss_bytes{worker_id="0"} 73400320
swoole_worker_memory_rss_bytes{worker_id="1"} 71303168
...
```

How it works: every worker samples itself every `sample_interval` ms into a
`Swoole\Table` (shared memory allocated by the master before the fork — no
IPC), and whichever worker receives the scrape renders the whole table plus
the server-wide counters. A worker that stops updating its row is stuck or
dead — its `last_sample_timestamp` goes stale. The table outlives worker
processes, which is what makes `worker_restarts_total` possible: a worker id
whose row already exists on `workerStart` was re-forked (OOM kill, fatal
error, recycling).

| metric | labels | source |
| --- | --- | --- |
| `<ns>_worker_memory_rss_bytes` / `_hwm_bytes` | `worker_id` | `/proc/<pid>/status` VmRSS / VmHWM (Linux only — omitted elsewhere) |
| `<ns>_worker_php_memory_bytes` / `_peak_bytes` | `worker_id` | `memory_get_usage(true)` / `memory_get_peak_usage(true)` |
| `<ns>_worker_requests_total` | `worker_id` | `stats()['worker_request_count']` |
| `<ns>_worker_restarts_total` | `worker_id` | re-forks of this worker id since server start |
| `<ns>_worker_start_time_seconds`, `<ns>_worker_last_sample_timestamp_seconds` | `worker_id` | worker clock |
| `<ns>_process_memory_rss_bytes` | `process=master\|manager` | `/proc` |
| `<ns>_server_connections`, `_workers`, `_idle_workers`, `_requests_total`, `_accepted_total`, `_coroutines`, `_start_time_seconds` | — | `Swoole\Server::stats()` |
| `<ns>_php_memory_limit_bytes` | — | `ini_get('memory_limit')` (omitted when `-1`) |

Label cardinality is fixed (`worker_id` ∈ 0..N-1), so the series count does
not grow with traffic. The endpoint has **no authentication**: expose the
server port to your monitoring network only and do not route the path through
the public reverse proxy. Scrape example:

```yaml
scrape_configs:
    - job_name: app
      dns_sd_configs:
          - names: [app]     # one A record per replica
            type: A
            port: 8000
```

Useful alerts: `worker_memory_rss_bytes / php_memory_limit_bytes > 0.7`,
`increase(worker_restarts_total[15m]) > 0`,
`predict_linear(worker_memory_rss_bytes[2h], 6*3600) > php_memory_limit_bytes`.

## Static files: put nginx in front

Swoole does not serve files from `public/` — out of the box a request for
`/assets/app-1a2b3c.css` goes to a worker and ends as a Symfony 404. In
production, **run a reverse proxy (nginx) that serves a file when it exists on
disk and forwards only the remaining requests to Swoole**. A static file then
never occupies a worker: workers handle one request at a time, so every asset
they serve is a PHP request that does not get served.

```nginx
upstream swoole {
    server 127.0.0.1:8000;
    keepalive 32;               # reuse connections to Swoole
}

server {
    listen 80;
    server_name example.com;
    root /var/www/app/public;   # the same public/ the application uses

    # The file exists → nginx sends it; otherwise → Swoole.
    location / {
        try_files $uri @swoole;
    }

    # Fingerprinted AssetMapper/Encore files never change under the same URL.
    location /assets/ {
        try_files $uri @swoole;
        add_header Cache-Control "public, max-age=31536000, immutable";
        access_log off;
    }

    # Never hand out PHP sources as static files.
    location ~ \.php$ {
        return 404;
    }

    location @swoole {
        proxy_pass http://swoole;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
    }
}
```

- nginx needs the built assets on its own filesystem: run
  `php bin/console asset-map:compile` (or your Encore build) at deploy time and
  share `public/` with the nginx container (volume or a copy in its image).
- Keep nginx's default `proxy_buffering on`: nginx reads the response from the
  worker at full speed and trickles it to slow clients itself, so the worker
  is free for the next request. For SSE/streamed responses send
  `X-Accel-Buffering: no` from the response to turn buffering off per request.
- Configure trusted proxies (next section), otherwise Symfony sees the proxy's
  IP and plain HTTP.

Without a proxy (local development, a quick demo) Swoole's built-in static
handler can fill the gap — the DSN passes the options through:

```dotenv
SWOOLE_SERVER_DSN=swoole://0.0.0.0:8000?workers=2&enable_static_handler=true&document_root=/var/www/app/public
```

Treat it as a fallback, not the production setup: it has no per-location cache
headers, no buffering for slow clients, and the files are sent by the same
Swoole processes that should be busy with PHP traffic.
(`.php` files are not served by it — they fall through to the kernel.)

## Behind a reverse proxy (trusted proxies)

The bridge builds the `Request` from raw Swoole data, so behind nginx/traefik
the standard Symfony rules apply: without configured trusted proxies,
`Request::getClientIp()` returns the proxy's IP and `isSecure()` is `false`
even for TLS-terminated traffic. Configure it as usual:

```yaml
# config/packages/framework.yaml
framework:
    trusted_proxies: '%env(TRUSTED_PROXIES)%'
    trusted_headers: ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-host', 'x-forwarded-port']
```

## Hot-reload (dev)

In debug mode a watcher (mtime polling — works also on Docker Desktop/macOS
volumes, where inotify does not receive events from the host) scans
`watch_dirs` and on any change **stops the whole server** (`$server->shutdown()`)
— docker's/supervisor's restart policy brings up a fresh process, so the code
is guaranteed to come up new. A graceful `$server->reload()` is deliberately
not used: with 1 worker it does not work at all (ERRNO 507), and in debug a
re-forked worker serves the old code anyway (class definitions sit in the
master's memory after the DI container compilation).

Example for docker-compose:

```yaml
services:
    app:
        command: bin/console byfareska:swoole:server:start
        restart: unless-stopped
```

## Streaming (SSE / chunks)

A regular `StreamedResponse` with a callback returning a generator is
supported. For full control over the chunks use `ChunkYieldingStreamedResponse`
(or implement `ChunkYieldingResponseInterface` in your own response class):

```php
use Byfareska\SwooleServer\Http\ChunkYieldingStreamedResponse;

return new ChunkYieldingStreamedResponse(
    function (): iterable {
        foreach ($events as $event) {
            yield "data: {$event}\n\n";
        }
    },
    headers: ['Content-Type' => 'text/event-stream'],
);
```

The emitter reads chunks straight from the response (not through the
callback), because HttpKernel wraps the callback with a wrapper that loses
the returned generator.

A callback that **echoes** its output instead of returning an iterable (the
classic FPM style) also works — the emitter captures the echo with an output
buffer and rewrites it onto `$res->write()`. This is a fallback path: it costs
an extra memory copy per chunk, loses logical chunk boundaries and logs a
warning — prefer returning an iterable or `ChunkYieldingResponseInterface`.

## File downloads

`BinaryFileResponse` is sent with Swoole's zero-copy `sendfile()` — the file
never passes through PHP memory, so `output_buffer_size` does not apply to
downloads. Range requests (`206 Partial Content`), `X-Sendfile`/
`X-Accel-Redirect` delegation, HEAD requests and `deleteFileAfterSend()` keep
their standard Symfony semantics; responses backed by an in-memory
`SplTempFileObject` fall back to a streamed copy.

## What the bundle does for you

- **State reset after every request** — the manual Swoole bridge does not go
  through `symfony/runtime`, so the bundle calls `services_resetter` itself
  (the equivalent of `$kernel->reset()`); without it Doctrine accumulates
  entities in the identity map (memory leak + "MySQL gone away"), and the
  token storage / profiler leak state between requests. In debug it
  additionally wipes the `FormDataCollector` buffers that its `reset()` does
  not clean up.
- **Exceptions outside the kernel** — a failure of the bridge itself gets a
  500 page (`HtmlErrorRenderer`), a log entry on stderr and a manually
  collected profile, so the request is visible in the profiler. The response
  format is replaceable: alias
  `Byfareska\SwooleServer\ErrorHandler\ExceptionResponseFactoryInterface` to
  your own service (or decorate the default one), e.g. to return JSON for an
  API.
- **`Response::prepare()` before emission** — the same fixups the FPM front
  controller does (HEAD responses without a body, Content-Length/charset,
  protocol version).
- **`APP_RUNTIME_MODE=web=1&worker=1`** — the kernel does not wrongly detect
  console mode despite the "cli" SAPI.
- **Clean worker teardown** — on worker stop/recycling the kernel is shut down
  (`$kernel->shutdown()`), flushing logs and closing connections.
- **Unhandled-exception logging** — through the PSR-3 logger (monolog channel
  `swoole_server`) when available, with a raw-stderr fallback otherwise.

## Extension points

| interface / mechanism | how to override |
| --- | --- |
| `ErrorHandler\ExceptionResponseFactoryInterface` | alias to your service (e.g. JSON errors for an API) |
| `Runtime\WorkerKernelFactoryInterface` | alias to your service (custom kernel boot, prewarming) |
| `Bridge\SwooleRequestFactoryInterface` | alias to your service (custom Swoole→HttpFoundation request mapping) |
| `Reset\WorkerResetterInterface` | escape hatch — prefer Symfony's `ResetInterface` (`kernel.reset`); implement this only for state it cannot reach |
| `Bridge\Body\ResponseBodyEmitterInterface` | just implement it — autoconfiguration tags it into the body emission chain (first `supports()` wins, tag priority orders; built-ins: BinaryFile 100, ChunkYielding 50, Streamed 25, Content fallback -100) |

## Flex recipe

The repository ships a recipe under `recipe/` (bundle registration, the
`config/packages/byfareska_swoole_server.yaml` file and the
`SWOOLE_SERVER_DSN` env entry). To have `composer require` apply it
automatically, publish it on a private recipes endpoint (e.g.
[symfony/recipes-checker](https://github.com/symfony/recipes) fork or a
private Flex server) under
`byfareska/swoole-server-bundle/<version>/`. Without Flex, follow the manual
installation steps above.

## Tests

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

The suite runs without ext-swoole — `tests/Stub/` ships minimal stand-ins for
`Swoole\Http\Request`/`Response` and the `SWOOLE_LOG_*` constants; with the
extension loaded the real classes win. Static analysis runs at PHPStan
`level: max` (Swoole symbols come from `swoole/ide-helper`).

## Custom worker resetters

**Prefer Symfony's standard mechanism first**: implement
`Symfony\Contracts\Service\ResetInterface` on the service that holds
per-request state (autoconfiguration tags it with `kernel.reset`). The bundle
runs `services_resetter` after every request, so such services are reset
automatically — and the same code keeps working under FPM, in tests and with
any other runtime.

`Byfareska\SwooleServer\Reset\WorkerResetterInterface` is an escape hatch for
the cases `ResetInterface` cannot cover — e.g. state living outside the
container (globals, static caches) or a reset that needs the kernel itself.
Implement it and register the class as a regular service — autoconfiguration
tags it with `byfareska_swoole_server.worker_resetter` and the bundle calls it
after every request. Use the tag's `priority` to control ordering (higher runs
first; the built-in `services_resetter` bridge runs at priority `100`).

```php
use Byfareska\SwooleServer\Reset\WorkerResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class InMemoryCacheResetter implements WorkerResetterInterface
{
    public function reset(KernelInterface $kernel): void
    {
        // release any per-request state your app accumulates
    }
}
```

```yaml
# only needed without autoconfigure or to set a priority explicitly:
services:
    App\Swoole\InMemoryCacheResetter:
        tags:
            - { name: byfareska_swoole_server.worker_resetter, priority: -10 }
```

## Structure

| class | responsibility |
| --- | --- |
| `Command\ServerStartCommand` | Swoole server configuration and startup |
| `Server\ServerDsn` | DSN parsing (host, port, workers, worker memory limit, Swoole options) |
| `Runtime\WorkerKernelFactoryInterface` | extension point: per-worker kernel boot |
| `Runtime\WorkerKernelFactory` | default implementation: fresh kernel per worker (after fork) |
| `Runtime\SwooleRequestHandler` | full request→kernel→response cycle + reset |
| `Bridge\SwooleRequestFactoryInterface` | extension point: Swoole Request → HttpFoundation Request mapping |
| `Bridge\SwooleRequestFactory` | default implementation of the request mapping |
| `Bridge\SwooleResponseEmitter` | status/headers/cookies + delegation to the body emitter chain |
| `Bridge\Body\ResponseBodyEmitterInterface` | extension point: body emission strategy per response kind |
| `Bridge\Body\BinaryFileBodyEmitter` | files via zero-copy `sendfile()` (ranges, X-Sendfile, temp-file fallback) |
| `Bridge\Body\ChunkYieldingBodyEmitter` | chunks read directly from `ChunkYieldingResponseInterface` |
| `Bridge\Body\StreamedBodyEmitter` | `StreamedResponse` callbacks (iterable or echo via ob_ capture) |
| `Bridge\Body\ContentBodyEmitter` | fallback: plain `getContent()` |
| `ErrorHandler\ExceptionResponseFactoryInterface` | extension point: format of the outside-the-kernel error response |
| `ErrorHandler\ExceptionResponseFactory` | default implementation: HTML 500 page |
| `Profiler\ExceptionProfileCollector` | manual profile for the exception path |
| `Reset\WorkerResetterInterface` | extension point: custom per-request resetters (tag `byfareska_swoole_server.worker_resetter`) |
| `Reset\WorkerStateResetter` | composite running all tagged resetters after every request |
| `Reset\SymfonyServicesResetter` | `services_resetter` bridge (the equivalent of `$kernel->reset()`) |
| `Reset\FormDataCollectorResetter` | workaround for the FormDataCollector leak (debug) |
| `HotReload\HotReloadWatcher` | mtime polling + server stop on change |
| `Metrics\ServerMetrics` | `/metrics` endpoint: table allocation before the fork, worker hooks, scrape response |
| `Metrics\WorkerSampler` | per-worker timer writing the worker's own row (RSS, PHP memory, requests, restarts) |
| `Metrics\WorkerMetricsTable` | `Swoole\Table` wrapper — one shared-memory row per worker id |
| `Metrics\ProcessMemoryReader` | VmRSS/VmHWM from `/proc/<pid>/status` |
| `Metrics\PrometheusTextRenderer` | snapshot → Prometheus text exposition (no client library) |
| `HotReload\DirectoryFingerprint` | mtime+file-count fingerprint of the watched dirs |

## License

Released under the [MIT License](LICENSE).

---

Made with ❤️ in Bydgoszcz 🇵🇱
