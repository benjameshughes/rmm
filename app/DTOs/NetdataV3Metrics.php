<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Collection;

/**
 * One `/api/v3/data` response. Agents from 0.8.0 query a window of
 * per-second rows (`result.data`, newest first, null for gaps); older agents
 * sent a single averaged point with only `view.dimensions.sts.avg` relied on.
 * Usage values are averaged over the window's non-null points and state
 * values (uptime, disk space, link speed) come from the newest point, so both
 * shapes parse the same way.
 */
final class NetdataV3Metrics
{
    /** @var array<string, mixed> */
    private array $data;

    /** @var Collection<int, array<string, float|null>>|null */
    private ?Collection $rows = null;

    public function __construct(mixed $input)
    {
        $this->data = match (true) {
            is_array($input) => $input,
            is_string($input) => $this->decodeJson($input),
            default => [],
        };
    }

    /** @return array<int, string> */
    public function getDimensionIds(): array
    {
        return $this->data['view']['dimensions']['ids'] ?? [];
    }

    /** @return array<int, float> */
    public function getAverageValues(): array
    {
        return $this->data['view']['dimensions']['sts']['avg'] ?? [];
    }

    /**
     * Each dimension averaged over the window's non-null points, or Netdata's
     * own average when the response carries no rows.
     *
     * @return array<string, float>
     */
    public function getDimensionAverages(): array
    {
        $rows = $this->rows();

        if ($rows->isEmpty()) {
            return $this->summaryAverages();
        }

        return collect($this->getDimensionIds())
            ->mapWithKeys(fn (string $id): array => [$id => $this->column($id)->filter(fn (?float $value): bool => $value !== null)->avg()])
            ->filter(fn (?float $average): bool => $average !== null)
            ->all();
    }

    /**
     * Each dimension's newest non-null point, or Netdata's own average when
     * the response carries no rows.
     *
     * @return array<string, float>
     */
    public function getDimensionLatest(): array
    {
        $rows = $this->rows();

        if ($rows->isEmpty()) {
            return $this->summaryAverages();
        }

        return collect($this->getDimensionIds())
            ->mapWithKeys(fn (string $id): array => [$id => $this->column($id)->first(fn (?float $value): bool => $value !== null)])
            ->filter(fn (?float $latest): bool => $latest !== null)
            ->all();
    }

    /**
     * Per-second rows keyed by unix time, newest first, each mapping dimension
     * id to its value. The columns follow `view.dimensions.ids`, whose ids
     * name the node by id where `result.labels` name it by hostname.
     *
     * @return Collection<int, array<string, float|null>>
     */
    public function rows(): Collection
    {
        return $this->rows ??= $this->parseRows();
    }

    /**
     * More than one point: a per-second window rather than one averaged point.
     */
    public function isWindow(): bool
    {
        return $this->rows()->count() > 1;
    }

    /**
     * A value worked out from each row, keyed by unix time, newest first.
     * Rows the calculation cannot use (every dimension a gap) are left out.
     *
     * @param  callable(array<string, float>): ?float  $calculate
     * @return Collection<int, float>
     */
    public function series(callable $calculate): Collection
    {
        return $this->rows()
            ->map(fn (array $row): array => array_filter($row, fn (?float $value): bool => $value !== null))
            ->reject(fn (array $row): bool => $row === [])
            ->map(fn (array $row): ?float => $calculate($row))
            ->filter(fn (?float $value): bool => $value !== null);
    }

    /**
     * One dimension's points as positive numbers: Netdata reports sent
     * traffic and blocked processes as negatives.
     *
     * @return Collection<int, float>
     */
    public function dimensionSeries(string $dimension): Collection
    {
        return $this->series(fn (array $dimensions): ?float => isset($dimensions[$dimension]) ? round(abs($dimensions[$dimension]), 2) : null);
    }

    public function getUnits(): ?string
    {
        $units = $this->data['view']['units'] ?? null;

        return is_string($units) ? $units : null;
    }

    public function getTitle(): ?string
    {
        return $this->data['view']['title'] ?? null;
    }

    public function hasData(): bool
    {
        return $this->getDimensionAverages() !== [];
    }

    /**
     * Dimensions of a query grouped by instance and dimension, averaged over
     * the window, whose ids look like `<dimension>,<prefix>.<instance><suffix>@<node>`.
     * Ungrouped ids (just `<dimension>`) never match, so averaged-together data is dropped.
     *
     * @return Collection<int, array{instance: string, dimension: string, value: float}>
     */
    public function groupedDimensions(string $prefix, string $suffix = ''): Collection
    {
        return $this->grouped($this->getDimensionAverages(), $prefix, $suffix);
    }

    /**
     * Grouped dimensions at the window's newest point, for state such as
     * disk space or link speed.
     *
     * @return Collection<int, array{instance: string, dimension: string, value: float}>
     */
    public function groupedLatestDimensions(string $prefix, string $suffix = ''): Collection
    {
        return $this->grouped($this->getDimensionLatest(), $prefix, $suffix);
    }

    /** @return array<string, mixed> */
    public function getRawData(): array
    {
        return $this->data;
    }

    /** @return Collection<int, array<string, float|null>> */
    private function parseRows(): Collection
    {
        $ids = $this->getDimensionIds();
        $data = $this->data['result']['data'] ?? [];

        if ($ids === [] || ! is_array($data)) {
            return collect();
        }

        return collect($data)
            ->filter(fn (mixed $row): bool => is_array($row) && count($row) === count($ids) + 1 && is_numeric($row[0]))
            ->mapWithKeys(fn (array $row): array => [(int) $row[0] => array_combine($ids, array_map(
                fn (mixed $value): ?float => is_numeric($value) ? (float) $value : null,
                array_slice($row, 1),
            ))])
            ->sortKeysDesc();
    }

    /**
     * One dimension's points, newest first. Ids hold dots, so they cannot go through pluck().
     *
     * @return Collection<int, float|null>
     */
    private function column(string $id): Collection
    {
        return $this->rows()->map(fn (array $row): ?float => $row[$id] ?? null);
    }

    /** @return array<string, float> */
    private function summaryAverages(): array
    {
        $averages = $this->getAverageValues();

        return collect($this->getDimensionIds())
            ->mapWithKeys(fn (string $id, int $i): array => isset($averages[$i]) ? [$id => (float) $averages[$i]] : [])
            ->all();
    }

    /**
     * @param  array<string, float>  $values
     * @return Collection<int, array{instance: string, dimension: string, value: float}>
     */
    private function grouped(array $values, string $prefix, string $suffix): Collection
    {
        $pattern = '/^(?<dimension>[^,]+),'.preg_quote($prefix, '/').'\.(?<instance>.+)'.preg_quote($suffix, '/').'@[^@]+$/';

        return collect($values)
            ->map(fn (float $value, string $id): ?array => preg_match($pattern, $id, $match)
                ? ['instance' => $match['instance'], 'dimension' => $match['dimension'], 'value' => $value]
                : null)
            ->filter()
            ->values();
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $input): array
    {
        $decoded = json_decode($input, true);

        return is_array($decoded) ? $decoded : [];
    }
}
