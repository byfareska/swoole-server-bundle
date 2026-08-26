<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Swoole constants — values as defined by ext-swoole; declared only when the
// extension is absent, so the suite runs without it.
foreach ([
    'SWOOLE_LOG_DEBUG' => 0,
    'SWOOLE_LOG_TRACE' => 1,
    'SWOOLE_LOG_INFO' => 2,
    'SWOOLE_LOG_NOTICE' => 3,
    'SWOOLE_LOG_WARNING' => 4,
    'SWOOLE_LOG_ERROR' => 5,
    'SWOOLE_LOG_NONE' => 6,
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

require_once __DIR__ . '/Stub/swoole_http_stubs.php';
require_once __DIR__ . '/Stub/swoole_table_stub.php';
