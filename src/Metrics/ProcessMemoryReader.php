<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Metrics;

/**
 * Reads resident memory of a process from /proc/<pid>/status (Linux only).
 *
 * "status" rather than "statm": statm reports pages and the page size differs
 * between architectures (4K on x86-64, 16K on some arm64 kernels), while
 * status already reports kB. Any process of the same user is readable, so a
 * worker can look at the master and manager processes as well as itself.
 */
final class ProcessMemoryReader
{
    public function __construct(
        private readonly string $procPath = '/proc',
    ) {
    }

    public function isAvailable(): bool
    {
        return is_readable($this->procPath . '/self/status');
    }

    /**
     * Null when /proc is not available (macOS, restricted containers) or the
     * process is gone — callers skip the metric instead of reporting zeros.
     */
    public function read(int $pid): ?ProcessMemory
    {
        $status = @file_get_contents(\sprintf('%s/%d/status', $this->procPath, $pid));
        if (false === $status) {
            return null;
        }

        return self::parse($status);
    }

    public static function parse(string $status): ?ProcessMemory
    {
        $rss = self::field($status, 'VmRSS');
        $hwm = self::field($status, 'VmHWM');
        if (null === $rss) {
            // A zombie or a kernel thread has no VmRSS line.
            return null;
        }

        return new ProcessMemory($rss, $hwm ?? $rss);
    }

    private static function field(string $status, string $name): ?int
    {
        if (1 !== preg_match('/^' . $name . ':\s+(\d+)\s+kB$/m', $status, $m)) {
            return null;
        }

        return (int) $m[1] * 1024;
    }
}
