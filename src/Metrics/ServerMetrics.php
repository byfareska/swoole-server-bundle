<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Metrics;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

/**
 * Optional server-level metrics (config "metrics.enabled"): a Prometheus
 * text endpoint answered before the kernel — like the health check — so it
 * costs no Symfony boot, works while workers are booting and keeps reporting
 * when the kernel is broken.
 *
 * Model: every worker samples itself into a shared Swoole\Table
 * (WorkerSampler); the worker that receives the scrape renders all rows plus
 * the server-wide counters and the master/manager RSS.
 *
 * The endpoint carries no authentication — expose the server port to your
 * monitoring network only and do not route the path through the public
 * reverse proxy.
 */
final class ServerMetrics
{
    public function __construct(
        private readonly WorkerMetricsTable $table,
        private readonly WorkerSampler $sampler,
        private readonly ProcessMemoryReader $memory,
        private readonly PrometheusTextRenderer $renderer,
        private readonly string $path,
    ) {
    }

    /**
     * Master process, before $server->start(): shared memory must be allocated
     * before the fork or the workers will not see each other's rows.
     */
    public function prepare(int $workerNum): void
    {
        $this->table->create($workerNum);
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        $this->sampler->onWorkerStart($server, $workerId);
    }

    /**
     * Both workerExit and workerStop: idempotent, clears the sampling timer so
     * the worker's event loop can drain.
     */
    public function onWorkerExit(): void
    {
        $this->sampler->stop();
    }

    public function handles(Request $request): bool
    {
        return $this->path === ($request->server['request_uri'] ?? '');
    }

    public function respond(Server $server, Response $response): void
    {
        // The serving worker's own row is refreshed first, so at least one row
        // is never older than the scrape itself.
        $this->sampler->sampleNow($server);

        $response->status(200);
        $response->header('Content-Type', PrometheusTextRenderer::CONTENT_TYPE);
        $response->end($this->renderer->render($this->snapshot($server)));
    }

    private function snapshot(Server $server): ServerMetricsSnapshot
    {
        $memoryLimit = \ini_get('memory_limit');

        return new ServerMetricsSnapshot(
            workers: $this->table->all(),
            server: ServerStats::fromSwoole($server->stats()),
            master: $this->memory->read($server->getMasterPid()),
            manager: $this->memory->read($server->getManagerPid()),
            phpMemoryLimitBytes: IniBytes::parse(\ini_get('memory_limit')),
        );
    }
}
