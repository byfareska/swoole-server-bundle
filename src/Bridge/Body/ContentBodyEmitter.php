<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Bridge\Body;

use Symfony\Component\HttpFoundation\Response;

/**
 * The fallback for plain responses — supports everything, registered with the
 * lowest priority so every other strategy gets a chance first.
 */
final class ContentBodyEmitter implements ResponseBodyEmitterInterface
{
    public function supports(Response $response): bool
    {
        return true;
    }

    public function emitBody(Response $response, \Swoole\Http\Response $res): void
    {
        // getContent() returns string|false (false e.g. for streamed responses)
        // — Swoole's end() accepts ?string, so normalize false to null.
        $content = $response->getContent();
        $res->end(\is_string($content) ? $content : null);
    }
}
