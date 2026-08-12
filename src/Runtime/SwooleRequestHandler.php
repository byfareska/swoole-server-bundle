<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Runtime;

use Byfareska\SwooleServer\Bridge\SwooleRequestFactoryInterface;
use Byfareska\SwooleServer\Bridge\SwooleResponseEmitter;
use Byfareska\SwooleServer\ErrorHandler\ExceptionResponseFactoryInterface;
use Byfareska\SwooleServer\Profiler\ExceptionProfileCollector;
use Byfareska\SwooleServer\Reset\WorkerStateResetter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * The Swoole↔Symfony bridge for a single request: request in → kernel →
 * response out, with handling of exceptions thrown outside the kernel and
 * a worker state reset after every request.
 */
final readonly class SwooleRequestHandler
{
    public function __construct(
        private SwooleRequestFactoryInterface $requestFactory,
        private SwooleResponseEmitter $responseEmitter,
        private ExceptionResponseFactoryInterface $exceptionResponseFactory,
        private ExceptionProfileCollector $profileCollector,
        private WorkerStateResetter $resetter,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(KernelInterface $kernel, \Swoole\Http\Request $req, \Swoole\Http\Response $res): void
    {
        $request = null;
        $emitted = false;

        try {
            $request = $this->requestFactory->createRequest($req);
            // catch: true → exceptions are handled by the kernel.exception pipeline:
            // security listeners (redirect to login, 403) and ErrorController (404/500).
            // The console error format under the "cli" SAPI only applies to the global
            // ErrorHandler::renderException (the uncaught path), not to this one.
            $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
            // Under FPM the front controller calls prepare(); the manual bridge
            // has to do it itself (HEAD without a body, Content-Length fixups,
            // charset, protocol-version tweaks).
            $response->prepare($request);
            $this->responseEmitter->emit($response, $res, $request);
            $emitted = true;
            if ($kernel instanceof TerminableInterface) {
                $kernel->terminate($request, $response);
            }
        } catch (\Throwable $e) {
            // Last line of defense: a bridge failure (createRequest/emit) or an
            // exception the kernel did not handle → 500 + a log entry.
            $this->logException($e, $req);

            // The response already reached the client (the exception came from
            // terminate() or a kernel.terminate listener) — the Swoole response
            // is closed, so there is nothing more to send.
            if (!$emitted) {
                $errorResponse = $this->exceptionResponseFactory->createResponse($e);
                // On this path kernel.response never fired (the exception happened
                // outside the kernel), so we collect and save the profile manually —
                // otherwise such requests would not show up in the profiler.
                if ($request instanceof Request) {
                    $errorResponse->prepare($request);
                    $this->profileCollector->collect($kernel, $request, $errorResponse, $e);
                }
                $this->responseEmitter->emit($errorResponse, $res, $request);
            }
        } finally {
            // CRUCIAL for a long-running worker: release per-request state.
            // The manual Swoole bridge does not go through symfony/runtime, so we
            // call services_resetter ourselves (the equivalent of $kernel->reset()).
            // Without it, Doctrine keeps entities from all requests in its identity
            // map (memory leak + "MySQL gone away"), and the token storage/profiler
            // leak state between requests.
            $this->resetter->reset($kernel);
        }
    }

    /**
     * PSR-3 logger when available; raw stderr as the fallback — this path must
     * never fail, and the worker's stderr always exists.
     */
    private function logException(\Throwable $e, \Swoole\Http\Request $req): void
    {
        $method = $req->server['request_method'] ?? '';
        $method = \is_string($method) ? $method : '';
        $uri = $req->server['request_uri'] ?? '';
        $uri = \is_string($uri) ? $uri : '';

        if (null !== $this->logger) {
            $this->logger->error('Unhandled exception outside the kernel for "{method} {uri}": {message}', [
                'exception' => $e,
                'method' => $method,
                'uri' => $uri,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        fwrite(\STDERR, \sprintf(
            "[request] %s %s - %s: %s @ %s:%s\n",
            $method,
            $uri,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));
    }
}
