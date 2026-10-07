<?php

namespace App\Support\DataTable;

use Illuminate\Support\Collection;

class ColumnSet
{
    public function __construct(private readonly array $columns) {}

    public static function make(iterable $columns): self
    {
        return new self(self::normalize($columns));
    }

    public function all(): array
    {
        return $this->columns;
    }

    /**
     * Columns the given user is allowed to see. Resolved server side so the
     * table body, the table header and any export share one decision.
     */
    public function visible(?object $user = null): Collection
    {
        return collect($this->columns)
            ->filter(fn (Column $column) => $column->isVisibleFor($user))
            ->values();
    }

    public function exportable(?object $user = null): Collection
    {
        return $this->visible($user)->filter(fn (Column $column) => $column->exportable);
    }

    public function find(string $key): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }

    public function has(string $key): bool
    {
        return $this->find($key) !== null;
    }

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    public function count(): int
    {
        return count($this->columns);
    }

    private static function normalize(iterable $columns): array
    {
        $normalized = [];

        foreach ($columns as $key => $column) {
            if (! $column instanceof Column) {
                throw new \InvalidArgumentException(sprintf(
                    'DataTable column [%s] must be an instance of %s.',
                    is_string($key) ? $key : (string) $key,
                    Column::class,
                ));
            }

            $normalized[] = $column;
        }

        return $normalized;
    }
}
