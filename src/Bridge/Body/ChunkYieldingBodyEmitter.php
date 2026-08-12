<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Bridge\Body;

use Byfareska\SwooleServer\Http\ChunkYieldingResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads chunks straight from the response, not through the callback: in its
 * finally block HttpKernel wraps the callback with a wrapper (push/pop
 * RequestStack) that invokes the original and loses the returned generator —
 * calling __invoke() on the wrapper yields null.
 *
 * Must run before {@see StreamedBodyEmitter} (higher tag priority):
 * ChunkYieldingStreamedResponse extends StreamedResponse.
 */
final class ChunkYieldingBodyEmitter implements ResponseBodyEmitterInterface
{
    public function supports(Response $response): bool
    {
        return $response instanceof ChunkYieldingResponseInterface;
    }

    public function emitBody(Response $response, \Swoole\Http\Response $res): void
    {
        \assert($response instanceof ChunkYieldingResponseInterface);

        foreach ($response->getChunks() as $chunk) {
            if ('' !== ($chunk = (string) $chunk)) {
                $res->write($chunk);
            }
        }
        $res->end();
    }
}
