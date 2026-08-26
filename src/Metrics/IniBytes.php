<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Metrics;

/**
 * php.ini shorthand ("2G", "128M", "512K", "1048576") → bytes.
 */
final class IniBytes
{
    /**
     * Null for "-1" (unlimited) and for values PHP would not accept either.
     */
    public static function parse(string $value): ?int
    {
        $value = trim($value);
        if ('' === $value || '-1' === $value) {
            return null;
        }
        if (1 !== preg_match('/^(\d+)\s*([kmg]?)$/i', $value, $m)) {
            return null;
        }

        $bytes = (int) $m[1];
        $bytes *= match (strtolower($m[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };

        return $bytes;
    }
}
