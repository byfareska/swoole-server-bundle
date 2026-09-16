<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Command;

use Byfareska\SwooleServer\HotReload\HotReloadWatcher;
use Byfareska\SwooleServer\Metrics\ServerMetrics;
use Byfareska\SwooleServer\Runtime\SwooleRequestHandler;
use Byfareska\SwooleServer\Runtime\WorkerKernelFactoryInterface;
use Byfareska\SwooleServer\Server\ServerDsn;
use Swoole\Http\Server;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Starts a long-running Swoole HTTP server.
 *
 * Each worker boots its own Symfony kernel (after fork) and handles ONE
 * request at a time (enable_coroutine=false) — a PHP-FPM-like model where
 * concurrency comes from the number of workers (the "workers" DSN parameter).
 * Coroutines are disabled on purpose: a worker has a single kernel, i.e. one
 * Doctrine connection and one identity map, so overlapping requests would
 * share state (e.g. the PDO error "Cannot execute queries while other
 * unbuffered queries are active").
 */
#[AsCommand('byfareska:swoole:server:start', 'Long-running HTTP server (Swoole)')]
final class ServerStartCommand extends Command
{
    public function __construct(
        private readonly WorkerKernelFactoryInterface $kernelFactory,
        private readonly SwooleRequestHandler $requestHandler,
        private readonly HotReloadWatcher $hotReloadWatcher,
        private readonly string $dsn,
        private readonly ?bool $hotReloadEnabled,
        private readonly ?string $healthCheckPath,
        private readonly bool $debug,
        // null = metrics disabled (config metrics.enabled)
        private readonly ?ServerMetrics $metrics = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dsn',
            null,
            InputOption::VALUE_REQUIRED,
            'Overrides the configured server DSN, e.g. swoole://127.0.0.1:9501?workers=2',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var ?string $dsnOption */
        $dsnOption = $input->getOption('dsn');
        $dsn = ServerDsn::fromString($dsnOption ?? $this->dsn);

        // The SAPI is "cli", so the kernel would detect console mode on its own
        // (e.g. errors rendered by CliErrorRenderer — ANSI instead of HTML pages).
        // Declare explicitly: this process serves web traffic in long-running workers.
        $_SERVER['APP_RUNTIME_MODE'] = $_ENV['APP_RUNTIME_MODE'] = 'web=1&worker=1';

        $server = new Server($dsn->host, $dsn->port);
        $server->set([
            // No coroutines: requests within a worker are serialized because they
            // share a single kernel (one Doctrine connection, one identity map).
            'enable_coroutine' => false,
            'http_compression' => false,
            'max_request' => 0,          // no worker recycling (long-lived connections)
            // Max size of a single request — Swoole (not PHP ini) limits uploads,
            // because the body is read by Swoole (php.ini: enable_post_data_reading=off).
            'package_max_length' => 64 * 1024 * 1024,
            // Max size of a buffered response body — Swoole's own default (2 MB)
            // aborts larger end() payloads with a warning and a broken response.
            'output_buffer_size' => 64 * 1024 * 1024,
            'log_level' => SWOOLE_LOG_INFO,
            // Query parameters from the DSN override the defaults above.
            ...$dsn->settings,
            'worker_num' => $workerNum = $dsn->workers > 0 ? $dsn->workers : swoole_cpu_num(),
        ]);

        // Shared memory for the metrics table must exist before the fork.
        $this->metrics?->prepare($workerNum);

        // Per-worker handler (each worker = separate process after fork → own kernel).
        $kernel = null;

        $server->on('workerStart', function (Server $server, int $workerId) use (&$kernel, $dsn): void {
            // Long-running workers inherit the CLI process limit, which is
            // usually -1 (unlimited) — a leaking worker then grows until the
            // kernel's OOM killer takes it, silently and possibly along with
            // other processes. Applied before the kernel boot so it covers the
            // boot too, and only here: master and manager keep the CLI limit.
            if (null !== $dsn->workerMemoryLimit) {
                ini_set('memory_limit', $dsn->workerMemoryLimit);
            }
            $kernel = $this->kernelFactory->create();
            $this->metrics?->onWorkerStart($server, $workerId);
        });

        // Clean per-worker teardown (flushes logs, closes connections) on worker
        // recycling and server stop.
        // Graceful exit (reload, shutdown): a worker leaves only once its event
        // loop is empty, so pending timers must be cleared here — otherwise
        // Swoole waits max_wait_time and force-kills the worker (ERRNO 9101).
        $server->on('workerExit', function (): void {
            $this->metrics?->onWorkerExit();
        });

        $server->on('workerStop', function () use (&$kernel): void {
            $this->metrics?->onWorkerExit();
            if ($kernel instanceof KernelInterface) {
                $kernel->shutdown();
                $kernel = null;
            }
        });

        $server->on('request', function (\Swoole\Http\Request $req, \Swoole\Http\Response $res) use (&$kernel, $server): void {
            // Health check answered before the kernel gate: it reports liveness of
            // the server process, so it responds 200 even while a worker is booting.
            if (null !== $this->healthCheckPath && $this->healthCheckPath === ($req->server['request_uri'] ?? '')) {
                $res->status(200);
                $res->header('Content-Type', 'text/plain');
                $res->end('ok');

                return;
            }

            // Metrics are served before the kernel gate for the same reason —
            // and so they keep reporting when the kernel itself is the problem.
            if (null !== $this->metrics && $this->metrics->handles($req)) {
                $this->metrics->respond($server, $res);

                return;
            }

            if (!$kernel instanceof KernelInterface) {
                // The worker has not booted its kernel yet.
                $res->status(503);
                $res->end();

                return;
            }

            $this->requestHandler->handle($kernel, $req, $res);
        });

        if ($this->hotReloadEnabled ?? $this->debug) {
            $this->hotReloadWatcher->attach($server, $output);
        }

        $io->success(\sprintf('HTTP Server (Swoole) is listening on %s:%d', $dsn->host, $dsn->port));

        $server->start();

        return Command::SUCCESS;
    }
}
