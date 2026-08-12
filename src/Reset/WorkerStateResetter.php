<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Reset;

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Composite over all resetters tagged "byfareska_swoole_server.worker_resetter"
 * (every {@see WorkerResetterInterface} implementation gets the tag via
 * autoconfiguration). Called after every request; ordering follows the tag
 * priority (higher first).
 */
final readonly class WorkerStateResetter
{
    /**
     * @param iterable<WorkerResetterInterface> $resetters
     */
    public function __construct(
        private iterable $resetters,
    ) {
    }

    public function reset(KernelInterface $kernel): void
    {
        foreach ($this->resetters as $resetter) {
            $resetter->reset($kernel);
        }
    }
}
