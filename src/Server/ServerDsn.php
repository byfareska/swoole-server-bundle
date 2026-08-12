<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Server;

/**
 * Server configuration from a single DSN (usually the SWOOLE_SERVER_DSN env var):
 *
 *     swoole://0.0.0.0:8000?workers=4&package_max_length=67108864&log_level=info
 *
 * The "workers" parameter is interpreted by the bundle (0 = swoole_cpu_num()),
 * all remaining query parameters are passed 1:1 to Swoole's $server->set() —
 * so any Swoole option can be configured without code changes.
 */
final readonly class ServerDsn
{
    /**
     * @param array<string, scalar> $settings extra options passed to $server->set()
     */
    public function __construct(
        public string $host,
        public int $port,
        public int $workers,
        public array $settings = [],
    ) {
    }

    public static function fromString(string $dsn): self
    {
        $parts = parse_url($dsn);
        if (false === $parts || !isset($parts['host'])) {
            throw new \InvalidArgumentException(\sprintf('Invalid Swoole server DSN: "%s". Expected format: "swoole://host:port?workers=4&...".', $dsn));
        }

        if (isset($parts['scheme']) && 'swoole' !== $parts['scheme']) {
            throw new \InvalidArgumentException(\sprintf('Unsupported scheme "%s" in DSN "%s" — the only supported one is "swoole://".', $parts['scheme'], $dsn));
        }

        parse_str($parts['query'] ?? '', $query);

        if (isset($query['worker_num'])) {
            // Passed through 1:1 it would be silently overwritten by the
            // "workers" logic — reject it instead of surprising the user.
            throw new \InvalidArgumentException(\sprintf('The "worker_num" parameter in DSN "%s" is not passed to Swoole — use "workers" instead (0 = swoole_cpu_num()).', $dsn));
        }

        $workers = (int) ($query['workers'] ?? 0);
        unset($query['workers']);

        $settings = [];
        foreach ($query as $key => $value) {
            if (!\is_string($value)) {
                throw new \InvalidArgumentException(\sprintf('Parameter "%s" in DSN "%s" must be a scalar (arrays are not supported).', (string) $key, $dsn));
            }
            $settings[(string) $key] = self::castSetting((string) $key, $value);
        }

        return new self($parts['host'], $parts['port'] ?? 8000, $workers, $settings);
    }

    /**
     * A query string carries strings only, while Swoole expects native types —
     * cast numbers and booleans; log_level also accepts level names.
     */
    private static function castSetting(string $key, string $value): int|bool|string
    {
        if ('log_level' === $key && !is_numeric($value)) {
            return match (strtolower($value)) {
                'debug' => SWOOLE_LOG_DEBUG,
                'trace' => SWOOLE_LOG_TRACE,
                'info' => SWOOLE_LOG_INFO,
                'notice' => SWOOLE_LOG_NOTICE,
                'warning' => SWOOLE_LOG_WARNING,
                'error' => SWOOLE_LOG_ERROR,
                'none' => SWOOLE_LOG_NONE,
                default => throw new \InvalidArgumentException(\sprintf('Unknown log_level "%s" in the DSN.', $value)),
            };
        }

        return match (strtolower($value)) {
            'true', 'on' => true,
            'false', 'off' => false,
            default => is_numeric($value) && (string) (int) $value === $value ? (int) $value : $value,
        };
    }
}
