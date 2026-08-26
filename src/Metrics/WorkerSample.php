<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Metrics;

/**
 * One row of the worker metrics table — everything a worker knows about itself
 * at sampling time. Written by the worker, read by whichever worker happens to
 * serve the metrics request.
 */
final readonly class WorkerSample
{
    public function __construct(
        public int $workerId,
        public int $pid,
        /** 0 when /proc is not available. */
        public int $rssBytes,
        public int $hwmBytes,
        /** memory_get_usage(true) — memory reserved by the Zend allocator. */
        public int $phpMemoryBytes,
        public int $phpPeakBytes,
        /** Requests handled by this worker process since it (re)started. */
        public int $requestCount,
        /** Unix timestamp of the current worker process start. */
        public int $startedAt,
        /** How many times a worker with this id was re-forked (0 = original process). */
        public int $restarts,
        /** Unix timestamp of this sample — a stale value means a stuck or dead worker. */
        public int $sampledAt,
    ) {
    }
}
