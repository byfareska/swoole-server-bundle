<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\ErrorHandler;

use Symfony\Component\HttpFoundation\Response;

/**
 * Renders an exception that the kernel.exception pipeline did not handle
 * (a failure of the bridge itself or an exception thrown outside the kernel)
 * into the response emitted to the client.
 *
 * Override by aliasing this interface to your own service (or decorating the
 * default {@see ExceptionResponseFactory}) — e.g. to return JSON for an API.
 */
interface ExceptionResponseFactoryInterface
{
    public function createResponse(\Throwable $exception): Response;
}
