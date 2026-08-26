<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Metrics;

/**
 * The server-wide part of Swoole\Server::stats() — the counters that are the
 * same no matter which worker process asks.
 */
final readonly class ServerStats
{
    public function __construct(
        public int $connections,
        public int $acceptedTotal,
        public int $requestsTotal,
        public int $workers,
        public int $idleWorkers,
        public int $coroutines,
        public int $startTime,
    ) {
    }

    /**
     * @param array<mixed> $stats the raw array returned by Swoole\Server::stats()
     */
    public static function fromSwoole(array $stats): self
    {
        return new self(
            connections: self::intField($stats, 'connection_num'),
            acceptedTotal: self::intField($stats, 'accept_count'),
            requestsTotal: self::intField($stats, 'request_count'),
            workers: self::intField($stats, 'worker_num'),
            idleWorkers: self::intField($stats, 'idle_worker_num'),
            coroutines: self::intField($stats, 'coroutine_num'),
            startTime: self::intField($stats, 'start_time'),
        );
    }

    /**
     * @param array<mixed> $stats
     */
    public static function intField(array $stats, string $key): int
    {
        $value = $stats[$key] ?? 0;

        return \is_int($value) ? $value : (int) (is_numeric($value) ? $value : 0);
    }
}
