<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Collection;

/**
 * One chart on the device Metrics tab. Flux draws a missing value as zero, so
 * a series the device never reports (load average on Windows) is dropped and
 * buckets where every remaining series is empty are left out as gaps.
 */
final class MetricChart
{
    /**
     * @param  array<string, string|int>  $format  Intl.NumberFormat options for the values
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, array{field: string, label: string, color: string, swatch: string}>  $series
     */
    public function __construct(
        public readonly string $title,
        public readonly array $format,
        public readonly Collection $rows,
        public readonly array $series,
    ) {}

    /**
     * @param  array<string, string|int>  $format
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, array{field: string, label: string, color: string, swatch: string}>  $series
     */
    public static function make(string $title, array $format, Collection $rows, array $series): self
    {
        $reportedSeries = collect($series)
            ->filter(fn (array $line): bool => $rows->contains(fn (array $row): bool => $row[$line['field']] !== null))
            ->values();

        $fields = $reportedSeries->pluck('field');

        $reportedRows = $rows
            ->filter(fn (array $row): bool => $fields->contains(fn (string $field): bool => $row[$field] !== null))
            ->map(fn (array $row): array => ['time' => $row['time'], ...collect($row)->only($fields)->all()])
            ->values();

        return new self($title, $format, $reportedRows, $reportedSeries->all());
    }

    /** @param  Collection<int, array<string, mixed>>  $rows  From DeviceMetricQueries::performance */
    public static function cpuAndMemory(Collection $rows): self
    {
        return self::make('CPU & RAM', self::percentFormat(), $rows, [
            ['field' => 'cpu', 'label' => 'CPU', 'color' => 'text-sky-500 dark:text-sky-400', 'swatch' => 'bg-sky-500'],
            ['field' => 'ram', 'label' => 'RAM', 'color' => 'text-violet-500 dark:text-violet-400', 'swatch' => 'bg-violet-500'],
        ]);
    }

    /** @param  Collection<int, array<string, mixed>>  $rows  From DeviceMetricQueries::performance */
    public static function diskAndPageFile(Collection $rows): self
    {
        return self::make('Disk busy & page file', self::percentFormat(), $rows, [
            ['field' => 'diskBusy', 'label' => 'Disk busy', 'color' => 'text-amber-500 dark:text-amber-400', 'swatch' => 'bg-amber-500'],
            ['field' => 'pageFile', 'label' => 'Page file', 'color' => 'text-rose-500 dark:text-rose-400', 'swatch' => 'bg-rose-500'],
        ]);
    }

    /**
     * Windows reports a CPU queue and Linux a load average; whichever the device sends is drawn.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  From DeviceMetricQueries::performance
     */
    public static function processorLoad(Collection $rows): self
    {
        return self::make('Processor load', ['maximumFractionDigits' => 2], $rows, [
            ['field' => 'cpuQueue', 'label' => 'CPU queue', 'color' => 'text-emerald-500 dark:text-emerald-400', 'swatch' => 'bg-emerald-500'],
            ['field' => 'load', 'label' => 'Load average', 'color' => 'text-teal-500 dark:text-teal-400', 'swatch' => 'bg-teal-500'],
        ]);
    }

    /** @param  Collection<int, array<string, mixed>>  $rows  From DeviceMetricQueries::network */
    public static function network(Collection $rows): self
    {
        return self::make('Network', ['style' => 'unit', 'unit' => 'kilobit-per-second', 'maximumFractionDigits' => 1], $rows, [
            ['field' => 'received', 'label' => 'In', 'color' => 'text-sky-500 dark:text-sky-400', 'swatch' => 'bg-sky-500'],
            ['field' => 'sent', 'label' => 'Out', 'color' => 'text-orange-500 dark:text-orange-400', 'swatch' => 'bg-orange-500'],
        ]);
    }

    /**
     * Flux needs two points to draw a line.
     */
    public function isDrawable(): bool
    {
        return $this->series !== [] && $this->rows->count() > 1;
    }

    /** @return array<int, array<string, mixed>> */
    public function points(): array
    {
        return $this->rows->all();
    }

    /** @return array<string, string|int> */
    private static function percentFormat(): array
    {
        return ['style' => 'unit', 'unit' => 'percent', 'maximumFractionDigits' => 1];
    }
}
