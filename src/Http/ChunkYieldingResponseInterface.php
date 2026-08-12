<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Http;

/**
 * A response whose chunks the emitter reads directly (without invoking the
 * StreamedResponse callback): in its finally block HttpKernel wraps the
 * callback with a wrapper (push/pop RequestStack) that invokes the original
 * and loses the returned generator — calling __invoke() on the wrapper
 * yields null.
 */
interface ChunkYieldingResponseInterface
{
    /**
     * @return iterable<string>
     */
    public function getChunks(): iterable;
}
