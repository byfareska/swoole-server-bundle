<?php

declare(strict_types=1);

// Minimal stand-ins for ext-swoole classes so the suite runs without the
// extension. Declared conditionally — with ext-swoole loaded the real classes
// win and these are skipped.

namespace Swoole\Http;

if (!class_exists(Request::class, false)) {
    class Request
    {
        public ?array $get = null;
        public ?array $post = null;
        public ?array $cookie = null;
        public ?array $files = null;
        public ?array $header = null;
        public ?array $server = null;

        public string $content = '';

        public function getContent(): string
        {
            return $this->content;
        }
    }
}

if (!class_exists(Response::class, false)) {
    class Response
    {
        public ?int $statusCode = null;

        /** @var list<array{string, string}> */
        public array $headers = [];

        /** @var list<array<mixed>> */
        public array $cookies = [];

        /** @var list<string> */
        public array $writes = [];

        public ?string $endContent = null;
        public bool $ended = false;

        public function status(int $statusCode): bool
        {
            $this->statusCode = $statusCode;

            return true;
        }

        /** @var list<array{string, string|list<string>}> raw header() calls */
        public array $headerCalls = [];

        /**
         * Like ext-swoole: an array value emits one header line per element,
         * while a repeated call with the same key overwrites the previous one —
         * $headers keeps only the lines that would reach the client.
         *
         * @param string|list<string> $value
         */
        public function header(string $key, string|array $value, bool $format = true): bool
        {
            $this->headerCalls[] = [$key, $value];
            $this->headers = array_values(array_filter(
                $this->headers,
                static fn (array $h): bool => 0 !== strcasecmp($h[0], $key),
            ));
            foreach ((array) $value as $v) {
                $this->headers[] = [$key, $v];
            }

            return true;
        }

        public function cookie(mixed ...$args): bool
        {
            $this->cookies[] = $args;

            return true;
        }

        public function write(string $data): bool
        {
            if ('' === $data) {
                return false; // real Swoole refuses empty writes
            }
            $this->writes[] = $data;

            return true;
        }

        public function end(?string $content = null): bool
        {
            $this->endContent = $content;
            $this->ended = true;

            return true;
        }

        /** @var list<array{string, int, int}> */
        public array $sentFiles = [];

        public function sendfile(string $filename, int $offset = 0, int $length = 0): bool
        {
            $this->sentFiles[] = [$filename, $offset, $length];
            $this->ended = true; // real Swoole completes the response itself

            return true;
        }
    }
}
