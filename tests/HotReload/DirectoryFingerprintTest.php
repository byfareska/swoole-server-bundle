<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Tests\HotReload;

use Byfareska\SwooleServer\HotReload\DirectoryFingerprint;
use PHPUnit\Framework\TestCase;

final class DirectoryFingerprintTest extends TestCase
{
    private string $dir;
    private DirectoryFingerprint $fingerprint;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fingerprint_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/nested', 0o777, true);
        $this->fingerprint = new DirectoryFingerprint();
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public function testStableForUnchangedFiles(): void
    {
        file_put_contents($this->dir . '/a.php', 'x');

        $first = $this->fingerprint->compute([$this->dir], ['php']);
        $second = $this->fingerprint->compute([$this->dir], ['php']);

        self::assertSame($first, $second);
    }

    public function testChangesWhenFileIsAdded(): void
    {
        file_put_contents($this->dir . '/a.php', 'x');
        $before = $this->fingerprint->compute([$this->dir], ['php']);

        file_put_contents($this->dir . '/nested/b.php', 'y');

        self::assertNotSame($before, $this->fingerprint->compute([$this->dir], ['php']));
    }

    public function testChangesWhenFileIsRemovedEvenWithSameMaxMtime(): void
    {
        file_put_contents($this->dir . '/a.php', 'x');
        file_put_contents($this->dir . '/b.php', 'y');
        $mtime = filemtime($this->dir . '/a.php');
        self::assertIsInt($mtime);
        touch($this->dir . '/b.php', $mtime);
        $before = $this->fingerprint->compute([$this->dir], ['php']);

        unlink($this->dir . '/b.php');

        self::assertNotSame($before, $this->fingerprint->compute([$this->dir], ['php']));
    }

    public function testChangesWhenMtimeMovesForward(): void
    {
        file_put_contents($this->dir . '/a.php', 'x');
        $before = $this->fingerprint->compute([$this->dir], ['php']);

        touch($this->dir . '/a.php', time() + 60);

        self::assertNotSame($before, $this->fingerprint->compute([$this->dir], ['php']));
    }

    public function testIgnoresUnwatchedExtensions(): void
    {
        file_put_contents($this->dir . '/a.php', 'x');
        $before = $this->fingerprint->compute([$this->dir], ['php']);

        file_put_contents($this->dir . '/notes.md', 'y');
        touch($this->dir . '/notes.md', time() + 60);

        self::assertSame($before, $this->fingerprint->compute([$this->dir], ['php']));
    }

    public function testMissingDirectoryIsTolerated(): void
    {
        self::assertSame(
            '0:0',
            $this->fingerprint->compute([$this->dir . '/does-not-exist'], ['php']),
        );
    }
}
