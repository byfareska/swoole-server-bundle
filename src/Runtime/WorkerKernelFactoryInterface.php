<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Runtime;

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Creates and boots a fresh kernel for a Swoole worker (called in the worker
 * process, after fork).
 *
 * Override by aliasing this interface to your own service — e.g. to prewarm
 * caches, tweak per-worker environment or boot a custom kernel setup.
 */
interface WorkerKernelFactoryInterface
{
    public function create(): KernelInterface;
}
