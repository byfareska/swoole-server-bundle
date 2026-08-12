<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Bridge\Body;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A StreamedResponse callback may return an iterable (the preferred path —
 * chunks go 1:1 to write()) or echo like under a classic SAPI. Echoed output
 * is captured with an output buffer (chunk_size=1 → forwarded after every
 * echo, without waiting for the buffer to fill up); otherwise it would end
 * up on the worker process's stdout instead of reaching the client. The ob_
 * path costs an extra memory copy per chunk and loses logical chunk
 * boundaries — hence the entry in the log.
 */
final readonly class StreamedBodyEmitter implements ResponseBodyEmitterInterface
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function supports(Response $response): bool
    {
        return $response instanceof StreamedResponse;
    }

    public function emitBody(Response $response, \Swoole\Http\Response $res): void
    {
        \assert($response instanceof StreamedResponse);

        $callback = $response->getCallback();
        if (null === $callback) {
            $res->end();

            return;
        }

        $echoed = false;
        ob_start(static function (string $buffer) use ($res, &$echoed): string {
            if ('' !== $buffer) {
                $echoed = true;
                $res->write($buffer);
            }

            return ''; // let nothing through to stdout
        }, 1);

        try {
            $chunks = $callback();
            if (is_iterable($chunks)) {
                foreach ($chunks as $chunk) {
                    if (!\is_scalar($chunk) && !$chunk instanceof \Stringable) {
                        continue; // nothing sensible to write for such a chunk
                    }
                    if ('' !== ($chunk = (string) $chunk)) {
                        $res->write($chunk);
                    }
                }
            }
        } finally {
            // Closes the buffer and pushes any leftover echo through the handler —
            // also on exceptions, so ob state does not leak into the next request.
            ob_end_flush();
        }

        if ($echoed) {
            $this->logger?->info(
                'StreamedResponse callback produced output via echo/print — captured through output buffering. '
                . 'This is suboptimal and not recommended: return an iterable from the callback or use '
                . 'ChunkYieldingResponseInterface to stream chunks directly.',
            );
        }

        $res->end();
    }
}
