<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\HotReload;

/**
 * A fingerprint of the file state: the highest mtime + the number of files
 * with the watched extensions. The count also catches adding/removing a file
 * (max-mtime alone would miss that).
 */
final class DirectoryFingerprint
{
    /**
     * @param list<string> $dirs
     * @param list<string> $extensions
     */
    public function compute(array $dirs, array $extensions): string
    {
        $latest = 0;
        $count = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }
                if (\in_array($file->getExtension(), $extensions, true)) {
                    $latest = max($latest, $file->getMTime());
                    ++$count;
                }
            }
        }

        return $latest . ':' . $count;
    }
}
