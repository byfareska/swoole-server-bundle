<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Bridge;

use Symfony\Component\HttpFoundation\Request;

/**
 * The default implementation.
 *
 * Keys in $req->server are lowercase (Swoole convention), while Symfony
 * expects uppercase ($_SERVER convention). Headers go under the HTTP_*
 * prefix, except Content-Type/Content-Length, which Symfony reads directly.
 */
final class SwooleRequestFactory implements SwooleRequestFactoryInterface
{
    public function createRequest(\Swoole\Http\Request $req): Request
    {
        $server = [];
        foreach ($req->server ?? [] as $key => $value) {
            $server[strtoupper((string) $key)] = $value;
        }

        foreach ($req->header ?? [] as $key => $value) {
            $name = strtoupper(str_replace('-', '_', (string) $key));
            if ('CONTENT_TYPE' === $name || 'CONTENT_LENGTH' === $name) {
                $server[$name] = $value;
            } else {
                $server['HTTP_' . $name] = $value;
            }
        }

        // Pass the raw body as content — this lets Request read payloads that
        // Swoole does not put into $req->post (e.g. JSON).
        return new Request(
            query: $req->get ?? [],
            request: $req->post ?? [],
            attributes: [],
            cookies: $req->cookie ?? [],
            files: $req->files ?? [],
            server: $server,
            content: $req->getContent() ?: null,
        );
    }
}
