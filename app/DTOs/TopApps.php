<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Collection;

/**
 * The busiest apps by CPU plus the biggest by memory, so an idle app hogging RAM
 * is kept alongside the ones burning CPU. Shared by the Windows and Linux agents.
 */
final class TopApps
{
    /**
     * @param  Collection<int|string, array{name: string, cpu_percent: float|null, memory_mib: float|null}>  $apps
     * @return array<int, array{name: string, cpu_percent: float|null, memory_mib: float|null}>
     */
    public static function pick(Collection $apps, int $limit): array
    {
        return self::largest($apps, 'cpu_percent', $limit)
            ->union(self::largest($apps, 'memory_mib', $limit))
            ->sortBy([['cpu_percent', 'desc'], ['memory_mib', 'desc']])
            ->values()
            ->all();
    }

    /**
     * Apps an agent reported directly, keyed by name so the CPU and memory picks merge.
     *
     * @param  array<int, array{name: string, cpu_percent?: float|int|null, memory_mib?: float|int|null}>  $apps
     * @return array<int, array{name: string, cpu_percent: float|null, memory_mib: float|null}>
     */
    public static function fromReport(array $apps, int $limit): array
    {
        return self::pick(
            collect($apps)
                ->filter(fn (mixed $app): bool => is_array($app) && isset($app['name']))
                ->mapWithKeys(fn (array $app): array => [$app['name'] => [
                    'name' => $app['name'],
                    'cpu_percent' => isset($app['cpu_percent']) ? round((float) $app['cpu_percent'], 2) : null,
                    'memory_mib' => isset($app['memory_mib']) ? round((float) $app['memory_mib'], 2) : null,
                ]]),
            $limit,
        );
    }

    /**
     * @param  Collection<int|string, array{name: string, cpu_percent: float|null, memory_mib: float|null}>  $apps
     * @return Collection<int|string, array{name: string, cpu_percent: float|null, memory_mib: float|null}>
     */
    private static function largest(Collection $apps, string $field, int $limit): Collection
    {
        return $apps
            ->filter(fn (array $app): bool => $app[$field] !== null)
            ->sortByDesc($field)
            ->take($limit);
    }
}
