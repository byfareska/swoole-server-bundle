<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Metrics;

/**
 * Resident memory of one OS process as the kernel sees it — the number that
 * actually matters for OOM kills, unlike memory_get_usage() which only covers
 * the Zend allocator.
 */
final readonly class ProcessMemory
{
    public function __construct(
        /** VmRSS — resident set size right now. */
        public int $rssBytes,
        /** VmHWM — the high-water mark of RSS since the process started. */
        public int $hwmBytes,
    ) {
    }
}
