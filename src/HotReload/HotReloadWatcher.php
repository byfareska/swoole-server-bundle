<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\HotReload;

use Swoole\Http\Server;
use Swoole\Timer;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Hot-reload in debug mode: an mtime watcher (polling — reliable also on
 * Docker Desktop/macOS volumes, where inotify does not receive events from
 * the host).
 *
 * Registers a tick in the master process that scans the project files and on
 * any change DELIBERATELY stops the whole server ($server->stop()) — a fresh
 * process is brought up by docker's/supervisor's restart policy, so the code
 * is guaranteed to come up new.
 *
 * A graceful $server->reload() does NOT work here (verified empirically):
 * - with a single worker (dev: workers=1) reload is not supported — Swoole
 *   logs ERRNO 507 and nothing happens;
 * - even in SWOOLE_PROCESS mode a re-forked worker serves the OLD PHP code:
 *   compiling the DI container at boot (debug) loads the application's class
 *   definitions into the master's memory via reflection, and the fork inherits
 *   them — requiring a fresh file is then a no-op and class edits never take
 *   effect.
 * The cost of stop() is dropping active connections (including SSE) —
 * acceptable, because hot-reload runs exclusively in debug (dev).
 */
final readonly class HotReloadWatcher
{
    /**
     * @param list<string> $watchDirs
     * @param int          $interval   scan interval in ms
     * @param list<string> $extensions
     */
    public function __construct(
        private DirectoryFingerprint $fingerprint,
        private array $watchDirs,
        private int $interval,
        private array $extensions,
    ) {
    }

    /**
     * The "start" event and the Timer::tick callbacks run in the master
     * process — the same one that runs the console command's execute() — so
     * the command's OutputInterface is fully usable here.
     */
    public function attach(Server $server, OutputInterface $output): void
    {
        $server->on('start', function (Server $server) use ($output): void {
            $last = $this->fingerprint->compute($this->watchDirs, $this->extensions);
            Timer::tick($this->interval, function () use ($server, $output, &$last): void {
                $now = $this->fingerprint->compute($this->watchDirs, $this->extensions);
                if ($now !== $last) {
                    $last = $now;
                    $output->writeln(
                        '<comment>[hot-reload] file change detected — stopping the server (docker restart policy)</comment>',
                    );
                    $server->stop();
                }
            });
        });
    }
}
