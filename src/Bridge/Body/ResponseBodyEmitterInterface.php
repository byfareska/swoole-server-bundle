<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Bridge\Body;

use Symfony\Component\HttpFoundation\Response;

/**
 * A strategy for emitting the BODY of one kind of Symfony response onto the
 * Swoole response. Status, headers and cookies are already sent by
 * {@see \Byfareska\SwooleServer\Bridge\SwooleResponseEmitter} before the
 * strategy runs; the strategy must complete the response (write()/end() or
 * sendfile()).
 *
 * Implementations are auto-tagged with
 * "byfareska_swoole_server.response_body_emitter" (autoconfiguration) —
 * registering a service implementing this interface is all it takes to plug
 * in support for a custom response class. The first strategy whose supports()
 * returns true wins; use the tag's "priority" attribute to order strategies
 * (higher runs first — keep custom ones above the built-in fallback and above
 * any built-in base class of your response, e.g. StreamedResponse at 25).
 */
interface ResponseBodyEmitterInterface
{
    public function supports(Response $response): bool;

    public function emitBody(Response $response, \Swoole\Http\Response $res): void;
}
