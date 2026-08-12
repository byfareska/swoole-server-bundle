<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Tests\Bridge;

use Byfareska\SwooleServer\Bridge\Body\BinaryFileBodyEmitter;
use Byfareska\SwooleServer\Bridge\Body\ChunkYieldingBodyEmitter;
use Byfareska\SwooleServer\Bridge\Body\ContentBodyEmitter;
use Byfareska\SwooleServer\Bridge\Body\ResponseBodyEmitterInterface;
use Byfareska\SwooleServer\Bridge\Body\StreamedBodyEmitter;
use Byfareska\SwooleServer\Bridge\SwooleResponseEmitter;
use Byfareska\SwooleServer\Http\ChunkYieldingStreamedResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SwooleResponseEmitterTest extends TestCase
{
    public function testEmitsStatusHeadersAndBody(): void
    {
        $res = new \Swoole\Http\Response();
        $response = new Response('hello', 201, ['X-Foo' => 'bar']);

        $this->createEmitter()->emit($response, $res);

        self::assertSame(201, $res->statusCode);
        self::assertContains(['X-Foo', 'bar'], $res->headers);
        self::assertSame('hello', $res->endContent);
        self::assertTrue($res->ended);
    }

    public function testEmitsCookies(): void
    {
        $res = new \Swoole\Http\Response();
        $response = new Response();
        $response->headers->setCookie(Cookie::create('sid', 'abc', 0, '/', null, true, true));

        $this->createEmitter()->emit($response, $res);

        self::assertCount(1, $res->cookies);
        self::assertSame('sid', $res->cookies[0][0]);
        self::assertSame('abc', $res->cookies[0][1]);
    }

    public function testFalseContentIsNormalizedToNull(): void
    {
        $res = new \Swoole\Http\Response();
        $response = new class extends Response {
            public function getContent(): string|false
            {
                return false;
            }
        };

        $this->createEmitter()->emit($response, $res);

        self::assertNull($res->endContent);
        self::assertTrue($res->ended);
    }

    public function testChunkYieldingResponseStreamsChunksDirectly(): void
    {
        $res = new \Swoole\Http\Response();
        $response = new ChunkYieldingStreamedResponse(static function (): iterable {
            yield 'one';
            yield 'two';
        });

        $this->createEmitter()->emit($response, $res);

        self::assertSame(['one', 'two'], $res->writes);
        self::assertTrue($res->ended);
        self::assertNull($res->endContent);
    }

    public function testStreamedResponseWithIterableReturningCallback(): void
    {
        $res = new \Swoole\Http\Response();
        $logger = new RecordingLogger();
        $response = new StreamedResponse(static fn (): iterable => ['a', '', 'b']);

        $this->createEmitter($logger)->emit($response, $res);

        self::assertSame(['a', 'b'], $res->writes, 'empty chunks must be skipped');
        self::assertTrue($res->ended);
        self::assertSame([], $logger->records, 'iterable path must not log');
    }

    public function testEchoingStreamedResponseCallbackIsCapturedAndLogged(): void
    {
        $res = new \Swoole\Http\Response();
        $logger = new RecordingLogger();
        $response = new StreamedResponse(static function (): void {
            echo 'first';
            echo 'second';
        });

        $this->createEmitter($logger)->emit($response, $res);

        self::assertSame(['first', 'second'], $res->writes);
        self::assertTrue($res->ended);
        self::assertCount(1, $logger->records);
        self::assertStringContainsString('output buffering', (string) $logger->records[0]['message']);
    }

    public function testEchoingCallbackDoesNotLeakOutputBufferOnException(): void
    {
        $res = new \Swoole\Http\Response();
        $level = ob_get_level();
        $response = new StreamedResponse(static function (): void {
            echo 'partial';
            throw new \RuntimeException('boom');
        });

        try {
            $this->createEmitter()->emit($response, $res);
            self::fail('exception should bubble up');
        } catch (\RuntimeException) {
        }

        self::assertSame($level, ob_get_level(), 'ob nesting must be restored');
        self::assertSame(['partial'], $res->writes, 'output emitted before the exception is flushed');
    }

    public function testStreamedResponseWithoutCallbackJustEnds(): void
    {
        $res = new \Swoole\Http\Response();

        $this->createEmitter()->emit(new StreamedResponse(), $res);

        self::assertSame([], $res->writes);
        self::assertTrue($res->ended);
    }

    public function testBinaryFileResponseIsSentWithSendfile(): void
    {
        $res = new \Swoole\Http\Response();
        $path = $this->createTempFile('file body');
        $response = new BinaryFileResponse($path);

        $this->createEmitter()->emit($response, $res);

        self::assertSame([[$path, 0, 0]], $res->sentFiles);
        self::assertTrue($res->ended);
        self::assertNull($res->endContent);
        self::assertFileExists($path);
    }

    public function testBinaryFileResponseRangeIsRecoveredFromContentRange(): void
    {
        $res = new \Swoole\Http\Response();
        $path = $this->createTempFile('0123456789');
        $response = new BinaryFileResponse($path);
        $request = Request::create('/download');
        $request->headers->set('Range', 'bytes=2-5');
        $response->prepare($request);

        $this->createEmitter()->emit($response, $res, $request);

        self::assertSame(206, $res->statusCode);
        self::assertSame([[$path, 2, 4]], $res->sentFiles);
    }

    public function testBinaryFileResponseUnsatisfiableRangeSendsNoBody(): void
    {
        $res = new \Swoole\Http\Response();
        $path = $this->createTempFile('0123456789');
        $response = new BinaryFileResponse($path);
        $request = Request::create('/download');
        $request->headers->set('Range', 'bytes=90-99');
        $response->prepare($request);

        $this->createEmitter()->emit($response, $res, $request);

        self::assertSame(416, $res->statusCode);
        self::assertSame([], $res->sentFiles);
        self::assertTrue($res->ended);
        self::assertNull($res->endContent);
    }

    public function testBinaryFileResponseDeleteFileAfterSend(): void
    {
        $res = new \Swoole\Http\Response();
        $path = $this->createTempFile('gone after send');
        $response = new BinaryFileResponse($path)->deleteFileAfterSend();

        $this->createEmitter()->emit($response, $res);

        self::assertSame([[$path, 0, 0]], $res->sentFiles);
        self::assertFileDoesNotExist($path);
    }

    public function testBinaryFileResponseFromTempFileObjectStreamsItsContent(): void
    {
        $res = new \Swoole\Http\Response();
        $file = new \SplTempFileObject();
        $file->fwrite('in-memory body');
        $response = new BinaryFileResponse($file);

        $this->createEmitter()->emit($response, $res);

        self::assertSame([], $res->sentFiles);
        self::assertSame('in-memory body', implode('', $res->writes));
        self::assertTrue($res->ended);
    }

    public function testHeadRequestGetsHeadersWithoutBody(): void
    {
        $res = new \Swoole\Http\Response();
        $path = $this->createTempFile('file body');
        $response = new BinaryFileResponse($path);
        $request = Request::create('/download', 'HEAD');
        $response->prepare($request);

        $this->createEmitter()->emit($response, $res, $request);

        self::assertSame([], $res->sentFiles);
        self::assertSame([], $res->writes);
        self::assertTrue($res->ended);
        self::assertNull($res->endContent);
        self::assertContains(['Content-Length', '9'], $res->headers);
    }

    public function testCustomStrategyRegisteredFirstWinsOverBuiltIns(): void
    {
        $res = new \Swoole\Http\Response();
        $custom = new class implements ResponseBodyEmitterInterface {
            public function supports(Response $response): bool
            {
                return $response instanceof StreamedResponse;
            }

            public function emitBody(Response $response, \Swoole\Http\Response $res): void
            {
                $res->end('custom');
            }
        };
        $emitter = new SwooleResponseEmitter([$custom, ...$this->defaultBodyEmitters()]);

        $emitter->emit(new StreamedResponse(static function (): void {
            echo 'built-in path';
        }), $res);

        self::assertSame('custom', $res->endContent);
        self::assertSame([], $res->writes, 'built-in StreamedBodyEmitter must not run');
    }

    private function createEmitter(?LoggerInterface $logger = null): SwooleResponseEmitter
    {
        return new SwooleResponseEmitter($this->defaultBodyEmitters($logger));
    }

    /**
     * The built-in chain in tag-priority order, as wired in config/services.php.
     *
     * @return list<ResponseBodyEmitterInterface>
     */
    private function defaultBodyEmitters(?LoggerInterface $logger = null): array
    {
        return [
            new BinaryFileBodyEmitter(),
            new ChunkYieldingBodyEmitter(),
            new StreamedBodyEmitter($logger),
            new ContentBodyEmitter(),
        ];
    }

    private function createTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'swoole_emitter_test_');
        self::assertIsString($path);
        file_put_contents($path, $content);
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return $path;
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|\Stringable, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}
