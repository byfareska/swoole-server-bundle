<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Bridge\Body;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A file goes out through Swoole's zero-copy sendfile() whenever it sits on
 * disk; sendfile() also completes the response, so no end() afterwards.
 * Range semantics computed by BinaryFileResponse::prepare() (offset/maxlen)
 * are protected, but fully recoverable from the outside: a 206 carries them
 * in Content-Range, a 416/304 sends no body at all, and an X-Sendfile /
 * X-Accel-Redirect response delegates the body to the fronting proxy.
 * In-memory files (SplTempFileObject) have no path to sendfile(), so their
 * sendContent() output is captured with an output buffer instead.
 */
final class BinaryFileBodyEmitter implements ResponseBodyEmitterInterface
{
    public function supports(Response $response): bool
    {
        return $response instanceof BinaryFileResponse;
    }

    public function emitBody(Response $response, \Swoole\Http\Response $res): void
    {
        \assert($response instanceof BinaryFileResponse);

        if (!$response->isSuccessful()
            || $response->headers->has('X-Sendfile')
            || $response->headers->has('X-Accel-Redirect')
        ) {
            $res->end();

            return;
        }

        $file = $response->getFile();
        $path = $file->getPathname();

        if (!is_file($path) || !$file->isReadable()) {
            // No file on disk (e.g. an SplTempFileObject-backed response) —
            // let sendContent() produce the body and forward it chunk by chunk.
            ob_start(static function (string $buffer) use ($res): string {
                if ('' !== $buffer) {
                    $res->write($buffer);
                }

                return '';
            }, 1);

            try {
                $response->sendContent();
            } finally {
                ob_end_flush();
            }
            $res->end();

            return;
        }

        [$offset, $length] = $this->fileRange($response);
        $res->sendfile($path, $offset, $length);

        // sendfile() reads the file lazily, but by the time it returns the data
        // has been handed to the kernel — mirroring sendContent()'s cleanup.
        if ($response->shouldDeleteFileAfterSend()) {
            unlink($path);
        }
    }

    /**
     * Recovers the byte range chosen by BinaryFileResponse::prepare() from the
     * Content-Range header of a 206 response ("bytes START-END/TOTAL").
     * Swoole's sendfile() treats length 0 as "until EOF".
     *
     * @return array{int, int} [offset, length]
     */
    private function fileRange(BinaryFileResponse $response): array
    {
        if (206 !== $response->getStatusCode()) {
            return [0, 0];
        }

        $contentRange = (string) $response->headers->get('Content-Range');
        if (1 !== preg_match('{^bytes (\d+)-(\d+)/}', $contentRange, $m)) {
            return [0, 0];
        }

        return [(int) $m[1], (int) $m[2] - (int) $m[1] + 1];
    }
}
