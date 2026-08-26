<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Tests\Metrics;

use Byfareska\SwooleServer\Metrics\ProcessMemory;
use Byfareska\SwooleServer\Metrics\PrometheusTextRenderer;
use Byfareska\SwooleServer\Metrics\ServerMetricsSnapshot;
use Byfareska\SwooleServer\Metrics\ServerStats;
use Byfareska\SwooleServer\Metrics\WorkerSample;
use PHPUnit\Framework\TestCase;

final class PrometheusTextRendererTest extends TestCase
{
    public function testRendersTheFullExposition(): void
    {
        $snapshot = new ServerMetricsSnapshot(
            workers: [
                new WorkerSample(0, 101, 70_000_000, 90_000_000, 30_000_000, 35_000_000, 120, 1_700_000_000, 0, 1_700_000_060),
                new WorkerSample(1, 102, 71_000_000, 91_000_000, 31_000_000, 36_000_000, 130, 1_700_000_010, 2, 1_700_000_061),
            ],
            server: new ServerStats(connections: 5, acceptedTotal: 400, requestsTotal: 250, workers: 2, idleWorkers: 1, coroutines: 0, startTime: 1_699_999_999),
            master: new ProcessMemory(20_000_000, 21_000_000),
            manager: new ProcessMemory(10_000_000, 11_000_000),
            phpMemoryLimitBytes: 2 * 1024 ** 3,
        );

        $expected = <<<'TXT'
            # HELP swoole_worker_memory_rss_bytes Resident set size (VmRSS) of the worker process.
            # TYPE swoole_worker_memory_rss_bytes gauge
            swoole_worker_memory_rss_bytes{worker_id="0"} 70000000
            swoole_worker_memory_rss_bytes{worker_id="1"} 71000000
            # HELP swoole_worker_memory_hwm_bytes Peak resident set size (VmHWM) of the worker process since it started.
            # TYPE swoole_worker_memory_hwm_bytes gauge
            swoole_worker_memory_hwm_bytes{worker_id="0"} 90000000
            swoole_worker_memory_hwm_bytes{worker_id="1"} 91000000
            # HELP swoole_worker_php_memory_bytes Memory reserved by the PHP allocator in the worker (memory_get_usage(true)).
            # TYPE swoole_worker_php_memory_bytes gauge
            swoole_worker_php_memory_bytes{worker_id="0"} 30000000
            swoole_worker_php_memory_bytes{worker_id="1"} 31000000
            # HELP swoole_worker_php_memory_peak_bytes Peak memory reserved by the PHP allocator in the worker (memory_get_peak_usage(true)).
            # TYPE swoole_worker_php_memory_peak_bytes gauge
            swoole_worker_php_memory_peak_bytes{worker_id="0"} 35000000
            swoole_worker_php_memory_peak_bytes{worker_id="1"} 36000000
            # HELP swoole_worker_requests_total Requests handled by the worker process since it (re)started.
            # TYPE swoole_worker_requests_total counter
            swoole_worker_requests_total{worker_id="0"} 120
            swoole_worker_requests_total{worker_id="1"} 130
            # HELP swoole_worker_start_time_seconds Unix time the current worker process started.
            # TYPE swoole_worker_start_time_seconds gauge
            swoole_worker_start_time_seconds{worker_id="0"} 1700000000
            swoole_worker_start_time_seconds{worker_id="1"} 1700000010
            # HELP swoole_worker_restarts_total How many times the worker with this id was re-forked (crash, OOM kill or recycling).
            # TYPE swoole_worker_restarts_total counter
            swoole_worker_restarts_total{worker_id="0"} 0
            swoole_worker_restarts_total{worker_id="1"} 2
            # HELP swoole_worker_last_sample_timestamp_seconds Unix time of the worker's last self-sample; a stale value means a stuck or dead worker.
            # TYPE swoole_worker_last_sample_timestamp_seconds gauge
            swoole_worker_last_sample_timestamp_seconds{worker_id="0"} 1700000060
            swoole_worker_last_sample_timestamp_seconds{worker_id="1"} 1700000061
            # HELP swoole_process_memory_rss_bytes Resident set size (VmRSS) of the Swoole master and manager processes.
            # TYPE swoole_process_memory_rss_bytes gauge
            swoole_process_memory_rss_bytes{process="master"} 20000000
            swoole_process_memory_rss_bytes{process="manager"} 10000000
            # HELP swoole_server_connections Currently open TCP connections.
            # TYPE swoole_server_connections gauge
            swoole_server_connections 5
            # HELP swoole_server_workers Configured number of event workers.
            # TYPE swoole_server_workers gauge
            swoole_server_workers 2
            # HELP swoole_server_idle_workers Event workers not handling a request right now.
            # TYPE swoole_server_idle_workers gauge
            swoole_server_idle_workers 1
            # HELP swoole_server_requests_total Requests received by the server since start.
            # TYPE swoole_server_requests_total counter
            swoole_server_requests_total 250
            # HELP swoole_server_accepted_total TCP connections accepted since start.
            # TYPE swoole_server_accepted_total counter
            swoole_server_accepted_total 400
            # HELP swoole_server_coroutines Active coroutines in the worker serving this scrape.
            # TYPE swoole_server_coroutines gauge
            swoole_server_coroutines 0
            # HELP swoole_server_start_time_seconds Unix time the server started.
            # TYPE swoole_server_start_time_seconds gauge
            swoole_server_start_time_seconds 1699999999
            # HELP swoole_php_memory_limit_bytes php.ini memory_limit of the worker processes.
            # TYPE swoole_php_memory_limit_bytes gauge
            swoole_php_memory_limit_bytes 2147483648

            TXT;

        self::assertSame($expected, new PrometheusTextRenderer('swoole')->render($snapshot));
    }

    public function testSkipsRssFamiliesWithoutProcAndLimitWhenUnlimited(): void
    {
        $snapshot = new ServerMetricsSnapshot(
            workers: [new WorkerSample(0, 101, 0, 0, 30_000_000, 35_000_000, 1, 1, 0, 2)],
            server: new ServerStats(0, 0, 0, 1, 1, 0, 1),
            master: null,
            manager: null,
            phpMemoryLimitBytes: null,
        );

        $out = new PrometheusTextRenderer('app')->render($snapshot);

        self::assertStringNotContainsString('memory_rss_bytes', $out);
        self::assertStringNotContainsString('memory_hwm_bytes', $out);
        self::assertStringNotContainsString('php_memory_limit_bytes', $out);
        self::assertStringContainsString('app_worker_php_memory_bytes{worker_id="0"} 30000000', $out);
        self::assertStringContainsString("app_server_workers 1\n", $out);
    }
}
