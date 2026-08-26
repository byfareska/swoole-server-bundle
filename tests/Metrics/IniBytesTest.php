<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Tests\Metrics;

use Byfareska\SwooleServer\Metrics\IniBytes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IniBytesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function values(): iterable
    {
        yield 'gigabytes' => ['2G', 2 * 1024 ** 3];
        yield 'megabytes' => ['128M', 128 * 1024 ** 2];
        yield 'kilobytes lower-case' => ['512k', 512 * 1024];
        yield 'plain bytes' => ['1048576', 1048576];
        yield 'unlimited' => ['-1', null];
        yield 'empty' => ['', null];
        yield 'garbage' => ['lots', null];
    }

    #[DataProvider('values')]
    public function testParse(string $value, ?int $expected): void
    {
        self::assertSame($expected, IniBytes::parse($value));
    }
}
