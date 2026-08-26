<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Metrics;

use Swoole\Table;

/**
 * Shared-memory table with one row per worker id.
 *
 * Swoole\Table lives in memory allocated by the master BEFORE the fork, so all
 * worker processes see the same rows without any IPC: each worker writes its
 * own row on a timer and any worker can render the whole table for a scrape.
 * The table survives a worker crash — that is what makes the restart counter
 * possible.
 */
final class WorkerMetricsTable
{
    private const array COLUMNS = [
        'pid',
        'rss_bytes',
        'hwm_bytes',
        'php_memory_bytes',
        'php_peak_bytes',
        'request_count',
        'started_at',
        'restarts',
        'sampled_at',
    ];

    private ?Table $table = null;

    /**
     * Must be called in the master process before $server->start().
     */
    public function create(int $rows): void
    {
        // Swoole rounds the size up to a power of two itself.
        $table = new Table(max(1, $rows));
        foreach (self::COLUMNS as $column) {
            $table->column($column, Table::TYPE_INT, 8);
        }
        $table->create();

        $this->table = $table;
    }

    /**
     * Restart counter stored for this worker id, or null when no process with
     * this id has ever written a row (first start after the server came up).
     */
    public function restartsOf(int $workerId): ?int
    {
        $row = $this->table()->get((string) $workerId);
        if (!\is_array($row)) {
            return null;
        }

        return self::int($row['restarts'] ?? null);
    }

    public function write(WorkerSample $sample): void
    {
        $this->table()->set((string) $sample->workerId, [
            'pid' => $sample->pid,
            'rss_bytes' => $sample->rssBytes,
            'hwm_bytes' => $sample->hwmBytes,
            'php_memory_bytes' => $sample->phpMemoryBytes,
            'php_peak_bytes' => $sample->phpPeakBytes,
            'request_count' => $sample->requestCount,
            'started_at' => $sample->startedAt,
            'restarts' => $sample->restarts,
            'sampled_at' => $sample->sampledAt,
        ]);
    }

    /**
     * @return list<WorkerSample> ordered by worker id
     */
    public function all(): array
    {
        $samples = [];
        foreach ($this->table() as $key => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $samples[] = new WorkerSample(
                workerId: self::int($key),
                pid: self::int($row['pid'] ?? null),
                rssBytes: self::int($row['rss_bytes'] ?? null),
                hwmBytes: self::int($row['hwm_bytes'] ?? null),
                phpMemoryBytes: self::int($row['php_memory_bytes'] ?? null),
                phpPeakBytes: self::int($row['php_peak_bytes'] ?? null),
                requestCount: self::int($row['request_count'] ?? null),
                startedAt: self::int($row['started_at'] ?? null),
                restarts: self::int($row['restarts'] ?? null),
                sampledAt: self::int($row['sampled_at'] ?? null),
            );
        }
        usort($samples, static fn (WorkerSample $a, WorkerSample $b): int => $a->workerId <=> $b->workerId);

        return $samples;
    }

    private function table(): Table
    {
        return $this->table ?? throw new \LogicException('The worker metrics table has not been created — call create() in the master process before $server->start().');
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : (int) (is_numeric($value) ? $value : 0);
    }
}
