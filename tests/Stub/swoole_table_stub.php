<?php

declare(strict_types=1);

// Array-backed stand-in for Swoole\Table so the metrics tests run without
// ext-swoole. Declared conditionally — with the extension loaded the real
// class wins. Only the subset the bundle calls is implemented.

namespace Swoole;

if (!class_exists(Table::class, false)) {
    /**
     * @implements \IteratorAggregate<string, array<string, int|float|string>>
     */
    class Table implements \IteratorAggregate, \Countable
    {
        public const TYPE_INT = 1;
        public const TYPE_FLOAT = 2;
        public const TYPE_STRING = 3;

        /** @var array<string, int> */
        private array $columns = [];

        /** @var array<string, array<string, int|float|string>> */
        private array $rows = [];

        private bool $created = false;

        public function __construct(
            public readonly int $size,
            public readonly float $conflictProportion = 0.2,
        ) {
        }

        public function column(string $name, int $type, int $size = 0): bool
        {
            $this->columns[$name] = $type;

            return true;
        }

        public function create(): bool
        {
            $this->created = true;

            return true;
        }

        /**
         * @param array<string, int|float|string> $value
         */
        public function set(string $key, array $value): bool
        {
            $this->assertCreated();
            $row = $this->rows[$key] ?? array_fill_keys(array_keys($this->columns), 0);
            foreach ($value as $column => $v) {
                if (!\array_key_exists($column, $this->columns)) {
                    throw new \RuntimeException(\sprintf('Unknown column "%s".', $column));
                }
                $row[$column] = $v;
            }
            $this->rows[$key] = $row;

            return true;
        }

        /**
         * @return array<string, int|float|string>|int|float|string|false
         */
        public function get(string $key, ?string $field = null): array|int|float|string|false
        {
            $this->assertCreated();
            if (!isset($this->rows[$key])) {
                return false;
            }

            return null === $field ? $this->rows[$key] : $this->rows[$key][$field];
        }

        public function exists(string $key): bool
        {
            return isset($this->rows[$key]);
        }

        public function del(string $key): bool
        {
            unset($this->rows[$key]);

            return true;
        }

        public function count(): int
        {
            return \count($this->rows);
        }

        public function getIterator(): \Iterator
        {
            return new \ArrayIterator($this->rows);
        }

        private function assertCreated(): void
        {
            if (!$this->created) {
                throw new \RuntimeException('Swoole\Table::create() has not been called.');
            }
        }
    }
}
