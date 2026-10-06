<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Null-safe reads from the decoded system-inventory JSON. The script runs on
 * machines we do not control, so every value is checked before it is shown.
 */
trait ReadsInventoryData
{
    protected function text(mixed $value): ?string
    {
        return is_scalar($value) && ! is_bool($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    protected function textAt(string $key): ?string
    {
        return $this->text(data_get($this->data, $key));
    }

    /**
     * A list from the JSON, tolerating a lone object where a list was expected.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function rows(string $key): Collection
    {
        $value = data_get($this->data, $key);

        if (! is_array($value)) {
            return collect();
        }

        return collect(array_is_list($value) ? $value : [$value])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values();
    }

    /**
     * @return list<string>
     */
    protected function strings(string $key): array
    {
        $value = data_get($this->data, $key);

        return collect(is_array($value) ? $value : [])
            ->map(fn (mixed $item): ?string => $this->text($item))
            ->filter()
            ->values()
            ->all();
    }

    protected function carbon(mixed $value): ?Carbon
    {
        return is_string($value) && strtotime($value) !== false ? Carbon::parse($value) : null;
    }

    protected function date(mixed $value): ?string
    {
        return $this->carbon($value)?->inDisplayTimezone()->format('j M Y');
    }

    protected function dateTime(mixed $value): ?string
    {
        return $this->carbon($value)?->inDisplayTimezone()->format('j M Y, H:i');
    }

    protected function gigabytes(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 1).' GB' : null;
    }

    protected function yesNo(mixed $value): ?string
    {
        return is_bool($value) ? ($value ? 'Yes' : 'No') : null;
    }

    /**
     * @param  array<string, Closure(array<string, mixed>): ?string>  $columns  Header => cell
     */
    protected function table(string $key, array $columns): InventoryTable
    {
        return $this->tableFrom($this->rows($key), $columns);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, Closure(array<string, mixed>): ?string>  $columns  Header => cell
     */
    protected function tableFrom(Collection $rows, array $columns): InventoryTable
    {
        return new InventoryTable(
            array_keys($columns),
            $rows->map(fn (array $row): array => collect($columns)->map(fn (Closure $cell): ?string => $cell($row))->values()->all())->values()->all(),
        );
    }

    /**
     * @param  array<string, ?string>  $facts
     * @return array<string, string>
     */
    protected function facts(array $facts): array
    {
        return array_filter($facts, fn (?string $value): bool => $value !== null);
    }

    /**
     * Joins the parts that are present, so a missing value never leaves a stray separator.
     */
    protected function joined(string $separator, ?string ...$parts): ?string
    {
        $present = array_filter($parts, fn (?string $part): bool => $part !== null);

        return $present === [] ? null : implode($separator, $present);
    }
}
