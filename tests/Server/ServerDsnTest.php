<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Tests\Server;

use Byfareska\SwooleServer\Server\ServerDsn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerDsnTest extends TestCase
{
    public function testParsesHostPortAndWorkers(): void
    {
        $dsn = ServerDsn::fromString('swoole://0.0.0.0:9501?workers=4');

        self::assertSame('0.0.0.0', $dsn->host);
        self::assertSame(9501, $dsn->port);
        self::assertSame(4, $dsn->workers);
        self::assertSame([], $dsn->settings);
    }

    public function testPortDefaultsTo8000AndWorkersToZero(): void
    {
        $dsn = ServerDsn::fromString('swoole://localhost');

        self::assertSame('localhost', $dsn->host);
        self::assertSame(8000, $dsn->port);
        self::assertSame(0, $dsn->workers);
    }

    public function testQueryParametersBecomeSwooleSettingsWithCasts(): void
    {
        $dsn = ServerDsn::fromString(
            'swoole://0.0.0.0:8000?package_max_length=67108864&http_compression=true&daemonize=off&pid_file=/tmp/x.pid',
        );

        self::assertSame([
            'package_max_length' => 67108864,
            'http_compression' => true,
            'daemonize' => false,
            'pid_file' => '/tmp/x.pid',
        ], $dsn->settings);
    }

    #[DataProvider('provideLogLevels')]
    public function testMapsLogLevelNamesToSwooleConstants(string $name, int $expected): void
    {
        $dsn = ServerDsn::fromString('swoole://h:1?log_level=' . $name);

        self::assertSame($expected, $dsn->settings['log_level']);
    }

    /**
     * @return iterable<array{string, int}>
     */
    public static function provideLogLevels(): iterable
    {
        yield ['debug', SWOOLE_LOG_DEBUG];
        yield ['trace', SWOOLE_LOG_TRACE];
        yield ['info', SWOOLE_LOG_INFO];
        yield ['notice', SWOOLE_LOG_NOTICE];
        yield ['WARNING', SWOOLE_LOG_WARNING];
        yield ['error', SWOOLE_LOG_ERROR];
        yield ['none', SWOOLE_LOG_NONE];
    }

    public function testNumericLogLevelPassesThroughAsInt(): void
    {
        $dsn = ServerDsn::fromString('swoole://h:1?log_level=5');

        self::assertSame(5, $dsn->settings['log_level']);
    }

    public function testWorkerNumParameterIsRejectedWithAHint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('use "workers" instead');

        ServerDsn::fromString('swoole://0.0.0.0:8000?worker_num=4');
    }

    public function testUnknownLogLevelNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown log_level');

        ServerDsn::fromString('swoole://h:1?log_level=verbose');
    }

    #[DataProvider('provideInvalidDsns')]
    public function testInvalidDsnThrows(string $dsn): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ServerDsn::fromString($dsn);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function provideInvalidDsns(): iterable
    {
        yield 'garbage' => ['not a dsn at all //'];
        yield 'missing host' => ['swoole://'];
        yield 'wrong scheme' => ['http://0.0.0.0:8000'];
        yield 'array parameter' => ['swoole://h:1?opt[]=1&opt[]=2'];
    }
}
