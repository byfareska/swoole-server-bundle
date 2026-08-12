<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Profiler;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * A manual equivalent of ProfilerListener for the outside-the-kernel exception path.
 *
 * Profiler::collect() passes the exception to the collectors (among others
 * ExceptionDataCollector) and appends the X-Debug-Token header to the response,
 * so it must receive the very same Response object that is later emitted to
 * the client.
 */
final class ExceptionProfileCollector
{
    public function collect(KernelInterface $kernel, Request $request, Response $response, \Throwable $e): void
    {
        $container = $kernel->getContainer();
        if (!$container->has('profiler')) {
            return; // e.g. the prod environment — the profiler does not exist
        }

        /** @var Profiler $profiler */
        $profiler = $container->get('profiler');
        if ($profile = $profiler->collect($request, $response, $e)) {
            $profiler->saveProfile($profile);
        }
    }
}
