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
- **every other query parameter** goes 1:1 to `$server->set()` — e.g.
  `package_max_length=134217728`, `log_level=warning` (level names are mapped
  to the `SWOOLE_LOG_*` constants), `http_compression=true`
- `true/false/on/off` values are cast to bool, numbers to int

Default server settings (overridable via the DSN):

| option | value | why |
| --- | --- | --- |
| `enable_coroutine` | `false` | requests within a worker are serialized — they share one kernel |
| `http_compression` | `false` | — |
| `max_request` | `0` | no worker recycling (long-lived connections) |
| `package_max_length` | 64 MB | the upload limit is enforced by Swoole, not PHP ini |
| `output_buffer_size` | 64 MB | Swoole's own default (2 MB) aborts larger buffered responses with a warning |
| `log_level` | `SWOOLE_LOG_INFO` | — |

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
`watch_dirs` and on any change **stops the whole server** (`$server->stop()`)
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
| `Server\ServerDsn` | DSN parsing (host, port, workers, Swoole options) |
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
| `HotReload\DirectoryFingerprint` | mtime+file-count fingerprint of the watched dirs |

## License

Released under the [MIT License](LICENSE).

---

Made with ❤️ in Bydgoszcz 🇵🇱
