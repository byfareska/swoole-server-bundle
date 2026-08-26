<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Tests\Metrics;

use Byfareska\SwooleServer\Metrics\WorkerMetricsTable;
use Byfareska\SwooleServer\Metrics\WorkerSample;
use PHPUnit\Framework\TestCase;

final class WorkerMetricsTableTest extends TestCase
{
    public function testRoundTripsSamplesOrderedByWorkerId(): void
    {
        $table = new WorkerMetricsTable();
        $table->create(2);

        $table->write(self::sample(1, restarts: 3));
        $table->write(self::sample(0));

        $all = $table->all();

        self::assertCount(2, $all);
        self::assertSame([0, 1], array_map(static fn (WorkerSample $s): int => $s->workerId, $all));
        self::assertEquals(self::sample(1, restarts: 3), $all[1]);
    }

    public function testRestartsOfUnknownWorkerIsNull(): void
    {
        $table = new WorkerMetricsTable();
        $table->create(1);

        self::assertNull($table->restartsOf(0));

        $table->write(self::sample(0, restarts: 2));

        self::assertSame(2, $table->restartsOf(0));
    }

    public function testUsingTheTableBeforeCreateIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        new WorkerMetricsTable()->all();
    }

    private static function sample(int $workerId, int $restarts = 0): WorkerSample
    {
        return new WorkerSample(
            workerId: $workerId,
            pid: 1000 + $workerId,
            rssBytes: 70_000_000,
            hwmBytes: 90_000_000,
            phpMemoryBytes: 30_000_000,
            phpPeakBytes: 35_000_000,
            requestCount: 120,
            startedAt: 1_700_000_000,
            restarts: $restarts,
            sampledAt: 1_700_000_060,
        );
    }
}
