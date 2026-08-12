<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Reset;

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Releases per-request state of a long-running Swoole worker. Called after
 * EVERY request (also on the exception path), so implementations must be
 * cheap and must not throw on missing optional services.
 *
 * Implementations are auto-tagged with "byfareska_swoole_server.worker_resetter"
 * (autoconfiguration) — registering a service implementing this interface is
 * all it takes to plug a custom resetter in. Use the tag's "priority"
 * attribute to control ordering (higher runs first).
 */
interface WorkerResetterInterface
{
    public function reset(KernelInterface $kernel): void;
}
