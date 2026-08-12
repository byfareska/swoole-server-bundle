<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Runtime;

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The default implementation: creates a fresh kernel for a Swoole worker
 * (each worker = separate process after fork → its own kernel, its own
 * Doctrine connection, its own identity map).
 */
final readonly class WorkerKernelFactory implements WorkerKernelFactoryInterface
{
    /**
     * @param class-string<KernelInterface>|null $kernelClass null = the application's kernel class
     */
    public function __construct(
        private KernelInterface $kernel,
        private ?string $kernelClass = null,
    ) {
    }

    public function create(): KernelInterface
    {
        /** @var class-string<KernelInterface> $class */
        $class = $this->kernelClass ?? $this->kernel::class;

        $kernel = new $class($this->kernel->getEnvironment(), $this->kernel->isDebug());
        $kernel->boot();

        return $kernel;
    }
}
