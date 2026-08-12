<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Bridge;

use Symfony\Component\HttpFoundation\Request;

/**
 * Maps a Swoole request onto an HttpFoundation {@see Request} object.
 *
 * Override by aliasing this interface to your own service (or decorating the
 * default {@see SwooleRequestFactory}) — e.g. to add extra attributes or
 * tweak the server variables before the kernel sees the request.
 */
interface SwooleRequestFactoryInterface
{
    public function createRequest(\Swoole\Http\Request $req): Request;
}
