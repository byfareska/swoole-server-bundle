<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Reset;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The equivalent of $kernel->reset() for the manual Swoole bridge: resets all
 * services tagged kernel.reset — among others doctrine (Registry::reset clears
 * the EM identity map and renews the connection), security.token_storage and
 * the profiler. Without it a long-running worker accumulates state (memory
 * leak + "MySQL gone away").
 */
final class SymfonyServicesResetter implements WorkerResetterInterface
{
    public function reset(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        if (!$container->has('services_resetter')) {
            return;
        }

        $resetter = $container->get('services_resetter');
        if ($resetter instanceof ResetInterface) {
            $resetter->reset();
        }
    }
}
