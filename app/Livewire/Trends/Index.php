<?php

declare(strict_types=1);

namespace App\Livewire\Trends;

use App\Actions\Trends\CompareTrendPeriods;
use App\DTOs\Trends\DeviceTrendRow;
use App\DTOs\Trends\DeviceTrendStats;
use App\DTOs\Trends\TrendChange;
use App\Enums\TrendMetric;
use App\Enums\TrendPeriod;
use App\Enums\TrendScope;
use App\Enums\TrendSort;
use App\Models\Device;
use App\Queries\TrendQueries;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The fleet's latest period against the one before, beside what was done to it meanwhile.
 * Measured, not explained: it never says one caused the other. Static, refreshed by navigating.
 */
#[Layout('components.layouts.app')]
#[Title('Trends')]
final class Index extends Component
{
    #[Url]
    public string $period = TrendPeriod::Week->value;

    #[Url]
    public string $platform = TrendScope::Windows->value;

    #[Url(as: 'sort')]
    public string $sortBy = TrendSort::Ram->value;

    #[Url(as: 'direction')]
    public string $sortDirection = 'asc';

    public function mount(): void
    {
        $this->authorize('viewAny', Device::class);
    }

    /**
     * Hand-edited query strings fall back to the defaults rather than erroring.
     */
    #[Computed]
    public function trendPeriod(): TrendPeriod
    {
        return TrendPeriod::tryFrom($this->period) ?? TrendPeriod::Week;
    }

    #[Computed]
    public function trendScope(): TrendScope
    {
        return TrendScope::tryFrom($this->platform) ?? TrendScope::Windows;
    }

    #[Computed]
    public function listSort(): TrendSort
    {
        return TrendSort::tryFrom($this->sortBy) ?? TrendSort::Ram;
    }

    #[Computed]
    public function listSortDirection(): string
    {
        return in_array($this->sortDirection, ['asc', 'desc'], true) ? $this->sortDirection : 'asc';
    }

    public function sort(string $column): void
    {
        $sort = TrendSort::tryFrom($column) ?? TrendSort::Ram;

        $this->sortDirection = $this->listSort === $sort
            ? ($this->listSortDirection === 'asc' ? 'desc' : 'asc')
            : $sort->defaultDirection();
        $this->sortBy = $sort->value;

        unset($this->listSort, $this->listSortDirection);
    }

    public function render(CompareTrendPeriods $compare, TrendQueries $trends): View
    {
        $comparison = $compare($this->trendPeriod, $this->trendScope);
        $changes = $trends->changes($comparison->window, $this->trendScope);

        return view('livewire.trends.index', [
            'comparison' => $comparison,
            'fleet' => $comparison->fleet(),
            'charts' => $comparison->charts(),
            'changes' => $changes,
            'rows' => $this->rows($comparison->stats, $changes),
            'periods' => TrendPeriod::cases(),
            'scopes' => TrendScope::cases(),
            'heroMetrics' => [TrendMetric::Ram, TrendMetric::Cpu, TrendMetric::Reboots, TrendMetric::BlankRate],
            'detailMetrics' => [TrendMetric::CpuPeak, TrendMetric::DiskBusy, TrendMetric::Swap, TrendMetric::OnlineHours],
        ]);
    }

    /**
     * @param  Collection<int, DeviceTrendStats>  $stats
     * @param  Collection<int, TrendChange>  $changes
     * @return Collection<int, DeviceTrendRow>
     */
    private function rows(Collection $stats, Collection $changes): Collection
    {
        $devices = Device::query()->with(['group', 'tags'])->whereKey($stats->keys())->get()->keyBy('id');
        $labelsByDevice = $changes
            ->flatMap(fn (TrendChange $change): array => collect($change->runsByDevice)->keys()
                ->map(fn (int $deviceId): array => ['deviceId' => $deviceId, 'label' => $change->labelFor($deviceId)])
                ->all())
            ->groupBy('deviceId');
        $sign = $this->listSortDirection === 'asc' ? 1 : -1;

        return $stats
            ->filter(fn (DeviceTrendStats $deviceStats): bool => $devices->has($deviceStats->deviceId))
            ->map(fn (DeviceTrendStats $deviceStats): DeviceTrendRow => new DeviceTrendRow(
                device: $devices->get($deviceStats->deviceId),
                stats: $deviceStats,
                changeLabels: $labelsByDevice->get($deviceStats->deviceId, collect())->pluck('label'),
            ))
            ->sort(function (DeviceTrendRow $first, DeviceTrendRow $second) use ($sign): int {
                [$firstGroup, $firstValue] = $first->sortKey($this->listSort);
                [$secondGroup, $secondValue] = $second->sortKey($this->listSort);

                return ($firstGroup <=> $secondGroup)
                    ?: ($sign * ($firstValue <=> $secondValue))
                    ?: strcasecmp($first->device->hostname, $second->device->hostname);
            })
            ->values();
    }
}
