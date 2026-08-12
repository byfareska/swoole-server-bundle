<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\ErrorHandler;

use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * The default implementation: the Swoole↔Symfony bridge's last line of
 * defense, rendering the exception as an HTML error page.
 */
final readonly class ExceptionResponseFactory implements ExceptionResponseFactoryInterface
{
    public function __construct(
        private HtmlErrorRenderer $errorRenderer,
    ) {
    }

    public function createResponse(\Throwable $exception): Response
    {
        $error = $this->errorRenderer->render($exception);

        return new Response(
            $error->getAsString(),
            $error->getStatusCode(),
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
