<?php

declare(strict_types=1);

namespace App\Queries;

use App\DTOs\Scripts\ScriptGroup;
use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Models\Script;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ScriptQueries
{
    /**
     * Scripts matching the search, grouped by category in the enum's order and by name within.
     *
     * @return Collection<int, ScriptGroup>
     */
    public function grouped(Builder $scripts, string $search = ''): Collection
    {
        return $scripts->search($search)
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Script $script): string => $script->category->value)
            ->map(fn (Collection $categoryScripts, string $category): ScriptGroup => new ScriptGroup(ScriptCategory::from($category), $categoryScripts->values()))
            ->sortBy(fn (ScriptGroup $group): int => $group->category->position())
            ->values();
    }

    /**
     * What a person may pick to run on a device of the platform.
     *
     * @return Collection<int, ScriptGroup>
     */
    public function runnableOn(ScriptPlatform $platform, string $search = ''): Collection
    {
        return $this->grouped(Script::query()->runnableDirectly()->forPlatform($platform), $search);
    }
}
