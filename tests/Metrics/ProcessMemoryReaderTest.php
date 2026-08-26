<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Tests\Metrics;

use Byfareska\SwooleServer\Metrics\ProcessMemoryReader;
use PHPUnit\Framework\TestCase;

final class ProcessMemoryReaderTest extends TestCase
{
    private const string STATUS = <<<'TXT'
        Name:	php
        Umask:	0022
        State:	S (sleeping)
        Pid:	42
        VmPeak:	  814548 kB
        VmSize:	  748012 kB
        VmHWM:	  102400 kB
        VmRSS:	   65536 kB
        RssAnon:	   40960 kB
        Threads:	1
        TXT;

    public function testParsesRssAndHwmInKilobytes(): void
    {
        $memory = ProcessMemoryReader::parse(self::STATUS);

        self::assertNotNull($memory);
        self::assertSame(65536 * 1024, $memory->rssBytes);
        self::assertSame(102400 * 1024, $memory->hwmBytes);
    }

    public function testProcessWithoutRssLineIsReportedAsUnknown(): void
    {
        self::assertNull(ProcessMemoryReader::parse("Name:\tkthreadd\nState:\tS (sleeping)\n"));
    }

    public function testHwmFallsBackToRssWhenMissing(): void
    {
        $memory = ProcessMemoryReader::parse("VmRSS:\t    1024 kB\n");

        self::assertNotNull($memory);
        self::assertSame(1024 * 1024, $memory->hwmBytes);
    }

    public function testReadsFromProcTree(): void
    {
        $proc = sys_get_temp_dir() . '/byfareska_proc_' . bin2hex(random_bytes(4));
        mkdir($proc . '/42', 0o777, true);
        file_put_contents($proc . '/42/status', self::STATUS);

        try {
            $reader = new ProcessMemoryReader($proc);

            self::assertSame(65536 * 1024, $reader->read(42)?->rssBytes);
            self::assertNull($reader->read(43), 'a missing process yields null, not zeros');
        } finally {
            unlink($proc . '/42/status');
            rmdir($proc . '/42');
            rmdir($proc);
        }
    }

    public function testMissingProcIsNotAvailable(): void
    {
        $reader = new ProcessMemoryReader('/nonexistent-proc');

        self::assertFalse($reader->isAvailable());
        self::assertNull($reader->read(1));
    }
}
