<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Metrics;

/**
 * Everything one scrape reports, decoupled from Swoole so renderers (and
 * future push sinks) can be tested without the extension.
 */
final readonly class ServerMetricsSnapshot
{
    /**
     * @param list<WorkerSample> $workers
     * @param ?int               $phpMemoryLimitBytes null when memory_limit is -1 (unlimited)
     */
    public function __construct(
        public array $workers,
        public ServerStats $server,
        public ?ProcessMemory $master,
        public ?ProcessMemory $manager,
        public ?int $phpMemoryLimitBytes,
    ) {
    }
}
