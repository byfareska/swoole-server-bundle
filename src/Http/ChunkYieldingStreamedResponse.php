<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Http;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A StreamedResponse whose chunks are also available as an iterable stream
 * ({@see ChunkYieldingResponseInterface}) — under Swoole the emitter rewrites
 * them onto $res->write(), while under a classic SAPI (FPM/CLI) the regular
 * sendContent() keeps working.
 */
class ChunkYieldingStreamedResponse extends StreamedResponse implements ChunkYieldingResponseInterface
{
    /**
     * @param \Closure(): iterable<string>       $chunks
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        private readonly \Closure $chunks,
        int $status = 200,
        array $headers = [],
    ) {
        parent::__construct(
            function (): void {
                foreach (($this->chunks)() as $chunk) {
                    echo $chunk;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            },
            $status,
            $headers,
        );
    }

    public function getChunks(): iterable
    {
        return ($this->chunks)();
    }
}
