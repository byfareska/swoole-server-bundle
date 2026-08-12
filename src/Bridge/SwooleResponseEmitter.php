<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Bridge;

use Byfareska\SwooleServer\Bridge\Body\ResponseBodyEmitterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rewrites a Symfony response into a Swoole response: sends status, headers
 * and cookies itself, then delegates the body to the first supporting
 * strategy tagged "byfareska_swoole_server.response_body_emitter"
 * ({@see ResponseBodyEmitterInterface}) — implement that interface to plug in
 * support for a custom response class.
 */
final readonly class SwooleResponseEmitter
{
    /**
     * @param iterable<ResponseBodyEmitterInterface> $bodyEmitters ordered by tag priority (higher first)
     */
    public function __construct(
        private iterable $bodyEmitters,
    ) {
    }

    public function emit(Response $response, \Swoole\Http\Response $res, ?Request $request = null): void
    {
        $res->status($response->getStatusCode());

        // ResponseHeaderBag guarantees array<string, list<string|null>>; the
        // guards only narrow the untyped `array` return for static analysis.
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            if (!\is_string($name) || !is_iterable($values)) {
                continue;
            }
            foreach ($values as $value) {
                if (\is_scalar($value) || $value instanceof \Stringable) {
                    $res->header($name, (string) $value);
                }
            }
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $res->cookie(
                $cookie->getName(),
                $cookie->getValue() ?? '',
                $cookie->getExpiresTime(),
                $cookie->getPath(),
                $cookie->getDomain() ?? '',
                $cookie->isSecure(),
                $cookie->isHttpOnly(),
                $cookie->getSameSite() ?? '',
            );
        }

        // A HEAD response carries headers only. Response::prepare() nulls the
        // content of a plain Response by itself, but the body-producing
        // strategies (files, streams, chunks) must be skipped explicitly.
        if (null !== $request && $request->isMethod('HEAD')) {
            $res->end();

            return;
        }

        foreach ($this->bodyEmitters as $emitter) {
            if ($emitter->supports($response)) {
                $emitter->emitBody($response, $res);

                return;
            }
        }

        // Unreachable with the built-in ContentBodyEmitter fallback registered,
        // but a misconfigured chain must not leave the connection hanging.
        $res->end();
    }
}
