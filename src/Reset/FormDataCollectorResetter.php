<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Reset;

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Workaround (debug): FormDataCollector::reset() (symfony/form) clears only
 * $data, leaving the dataByForm/dataByView/formsByView buffers behind — under
 * a long-running worker they grow indefinitely (the leak is visible in debug
 * only; prod has no collectors). We wipe them so that dev stays memory-wise
 * representative.
 *
 * Runs after {@see SymfonyServicesResetter} (lower tag priority) — first the
 * collector's own reset() does what it can, then we wipe the leftovers.
 */
final class FormDataCollectorResetter implements WorkerResetterInterface
{
    public function reset(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        if (!$kernel->isDebug() || !$container->has('data_collector.form')) {
            return;
        }

        $this->wipeBuffers($container->get('data_collector.form'));
    }

    /**
     * Zeroes out FormDataCollector's private buffers that its reset() does not
     * clean up. Reflection with a guard on property names — after a potential
     * symfony/form upgrade (changed properties) this simply becomes a no-op,
     * not an error.
     */
    private function wipeBuffers(object $collector): void
    {
        $reflection = new \ReflectionObject($collector);
        foreach (['dataByForm', 'dataByView', 'formsByView'] as $property) {
            if (!$reflection->hasProperty($property)) {
                continue;
            }
            $prop = $reflection->getProperty($property);
            $current = $prop->isInitialized($collector) ? $prop->getValue($collector) : null;
            $prop->setValue($collector, $current instanceof \SplObjectStorage ? new \SplObjectStorage() : []);
        }
    }
}
