<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Metrics;

use Swoole\Http\Server;
use Swoole\Timer;

/**
 * Runs inside every worker process: writes the worker's own row into the
 * shared table right after boot and then on a timer.
 *
 * Self-sampling on a timer (instead of the master polling /proc) keeps the
 * master process out of the loop and makes a stuck worker visible for free —
 * its "sampled_at" stops advancing. After the fork each worker owns its own
 * copy of this object, so the per-process fields below are naturally
 * per-worker.
 */
final class WorkerSampler
{
    private int|false|null $timerId = null;
    private ?int $workerId = null;
    private int $startedAt = 0;
    private int $restarts = 0;

    public function __construct(
        private readonly WorkerMetricsTable $table,
        private readonly ProcessMemoryReader $memory,
        /** Sampling interval in ms. */
        private readonly int $interval,
    ) {
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        $this->workerId = $workerId;
        $this->startedAt = time();
        // The table outlives the worker process: a row already present for this
        // id means the previous process with this id died (OOM kill, fatal
        // error) or was recycled and Swoole forked a replacement.
        $previous = $this->table->restartsOf($workerId);
        $this->restarts = null === $previous ? 0 : $previous + 1;

        $this->sample($server, $workerId);
        $this->timerId = Timer::tick($this->interval, function () use ($server, $workerId): void {
            $this->sample($server, $workerId);
        });
    }

    /**
     * Refreshes this worker's row immediately (no-op outside a started worker).
     */
    public function sampleNow(Server $server): void
    {
        if (null !== $this->workerId) {
            $this->sample($server, $this->workerId);
        }
    }

    public function stop(): void
    {
        if (\is_int($this->timerId)) {
            Timer::clear($this->timerId);
            $this->timerId = null;
        }
    }

    private function sample(Server $server, int $workerId): void
    {
        $memory = $this->memory->read(getmypid() ?: 0);
        $stats = $server->stats();

        $this->table->write(new WorkerSample(
            workerId: $workerId,
            pid: getmypid() ?: 0,
            rssBytes: null === $memory ? 0 : $memory->rssBytes,
            hwmBytes: null === $memory ? 0 : $memory->hwmBytes,
            phpMemoryBytes: memory_get_usage(true),
            phpPeakBytes: memory_get_peak_usage(true),
            requestCount: ServerStats::intField($stats, 'worker_request_count'),
            startedAt: $this->startedAt,
            restarts: $this->restarts,
            sampledAt: time(),
        ));
    }
}
