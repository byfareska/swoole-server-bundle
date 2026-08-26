<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Metrics;

/**
 * Prometheus text exposition format (version 0.0.4) — hand-rolled on purpose:
 * a dozen gauges do not justify a client library dependency in the bundle.
 *
 * Label cardinality is bounded by design: the only labels are "worker_id"
 * (0..worker_num-1) and "process" (master|manager). Never add request-derived
 * labels here.
 */
final readonly class PrometheusTextRenderer
{
    public const string CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    public function __construct(
        /** Metric name prefix, e.g. "swoole" → swoole_worker_memory_rss_bytes. */
        private string $namespace,
    ) {
    }

    public function render(ServerMetricsSnapshot $snapshot): string
    {
        $out = '';
        $workers = $snapshot->workers;
        $hasProc = [] !== array_filter($workers, static fn (WorkerSample $w): bool => $w->rssBytes > 0);

        if ($hasProc) {
            $out .= $this->workerGauge('worker_memory_rss_bytes', 'Resident set size (VmRSS) of the worker process.', $workers, static fn (WorkerSample $w): int => $w->rssBytes);
            $out .= $this->workerGauge('worker_memory_hwm_bytes', 'Peak resident set size (VmHWM) of the worker process since it started.', $workers, static fn (WorkerSample $w): int => $w->hwmBytes);
        }
        $out .= $this->workerGauge('worker_php_memory_bytes', 'Memory reserved by the PHP allocator in the worker (memory_get_usage(true)).', $workers, static fn (WorkerSample $w): int => $w->phpMemoryBytes);
        $out .= $this->workerGauge('worker_php_memory_peak_bytes', 'Peak memory reserved by the PHP allocator in the worker (memory_get_peak_usage(true)).', $workers, static fn (WorkerSample $w): int => $w->phpPeakBytes);
        $out .= $this->workerMetric('worker_requests_total', 'counter', 'Requests handled by the worker process since it (re)started.', $workers, static fn (WorkerSample $w): int => $w->requestCount);
        $out .= $this->workerGauge('worker_start_time_seconds', 'Unix time the current worker process started.', $workers, static fn (WorkerSample $w): int => $w->startedAt);
        $out .= $this->workerMetric('worker_restarts_total', 'counter', 'How many times the worker with this id was re-forked (crash, OOM kill or recycling).', $workers, static fn (WorkerSample $w): int => $w->restarts);
        $out .= $this->workerGauge('worker_last_sample_timestamp_seconds', 'Unix time of the worker\'s last self-sample; a stale value means a stuck or dead worker.', $workers, static fn (WorkerSample $w): int => $w->sampledAt);

        $processes = array_filter(['master' => $snapshot->master, 'manager' => $snapshot->manager]);
        if ([] !== $processes) {
            $out .= $this->header('process_memory_rss_bytes', 'gauge', 'Resident set size (VmRSS) of the Swoole master and manager processes.');
            foreach ($processes as $name => $memory) {
                $out .= $this->line('process_memory_rss_bytes', ['process' => $name], $memory->rssBytes);
            }
        }

        $server = $snapshot->server;
        $out .= $this->scalar('server_connections', 'gauge', 'Currently open TCP connections.', $server->connections);
        $out .= $this->scalar('server_workers', 'gauge', 'Configured number of event workers.', $server->workers);
        $out .= $this->scalar('server_idle_workers', 'gauge', 'Event workers not handling a request right now.', $server->idleWorkers);
        $out .= $this->scalar('server_requests_total', 'counter', 'Requests received by the server since start.', $server->requestsTotal);
        $out .= $this->scalar('server_accepted_total', 'counter', 'TCP connections accepted since start.', $server->acceptedTotal);
        $out .= $this->scalar('server_coroutines', 'gauge', 'Active coroutines in the worker serving this scrape.', $server->coroutines);
        $out .= $this->scalar('server_start_time_seconds', 'gauge', 'Unix time the server started.', $server->startTime);

        if (null !== $snapshot->phpMemoryLimitBytes) {
            $out .= $this->scalar('php_memory_limit_bytes', 'gauge', 'php.ini memory_limit of the worker processes.', $snapshot->phpMemoryLimitBytes);
        }

        return $out;
    }

    /**
     * @param list<WorkerSample>         $workers
     * @param callable(WorkerSample):int $value
     */
    private function workerGauge(string $name, string $help, array $workers, callable $value): string
    {
        return $this->workerMetric($name, 'gauge', $help, $workers, $value);
    }

    /**
     * @param list<WorkerSample>         $workers
     * @param callable(WorkerSample):int $value
     */
    private function workerMetric(string $name, string $type, string $help, array $workers, callable $value): string
    {
        $out = $this->header($name, $type, $help);
        foreach ($workers as $worker) {
            $out .= $this->line($name, ['worker_id' => (string) $worker->workerId], $value($worker));
        }

        return $out;
    }

    private function scalar(string $name, string $type, string $help, int $value): string
    {
        return $this->header($name, $type, $help) . $this->line($name, [], $value);
    }

    private function header(string $name, string $type, string $help): string
    {
        $full = $this->namespace . '_' . $name;

        return \sprintf("# HELP %s %s\n# TYPE %s %s\n", $full, self::escapeHelp($help), $full, $type);
    }

    /**
     * @param array<string, string> $labels
     */
    private function line(string $name, array $labels, int $value): string
    {
        $pairs = [];
        foreach ($labels as $label => $labelValue) {
            $pairs[] = \sprintf('%s="%s"', $label, self::escapeLabel($labelValue));
        }

        return \sprintf(
            "%s_%s%s %d\n",
            $this->namespace,
            $name,
            [] === $pairs ? '' : '{' . implode(',', $pairs) . '}',
            $value,
        );
    }

    private static function escapeHelp(string $text): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $text);
    }

    private static function escapeLabel(string $text): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $text);
    }
}
