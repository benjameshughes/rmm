<?php

declare(strict_types=1);

namespace App\Livewire\Scripts;

use App\DTOs\Scripts\ScriptGroup;
use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Livewire\Concerns\EntersScriptParameterValues;
use App\Livewire\Concerns\PicksScriptToRun;
use App\Livewire\Concerns\RunsScriptOnDevices;
use App\Models\Script;
use App\Queries\ScriptQueries;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Lazy]
final class Index extends Component
{
    use EntersScriptParameterValues;
    use PicksScriptToRun;
    use RunsScriptOnDevices;

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'category', except: '')]
    public string $categoryFilter = '';

    #[Url(as: 'platform', except: '')]
    public string $platformFilter = '';

    public function clearFilters(): void
    {
        $this->reset('search', 'categoryFilter', 'platformFilter');
    }

    public function delete(Script $script): void
    {
        abort_unless(! $script->is_system, 403);
        $script->delete();
    }

    public function placeholder(): View
    {
        return view('livewire.scripts.placeholders.index');
    }

    public function render(ScriptQueries $scriptQueries): View
    {
        $groups = $scriptQueries->grouped(Script::query()
            ->when($this->categoryFilter !== '', fn (Builder $q) => $q->where('category', $this->categoryFilter))
            ->when($this->platformFilter !== '', fn (Builder $q) => $q->where('platform', $this->platformFilter)), $this->search);

        return view('livewire.scripts.index', [
            'groups' => $groups,
            'summary' => $this->summary($groups),
            'isFiltered' => $this->isFiltered(),
            'categories' => ScriptCategory::cases(),
            'platforms' => ScriptPlatform::cases(),
            'runScript' => $this->showExecuteModal ? $this->scriptToRun() : null,
            'availableDevices' => $this->showExecuteModal ? $this->runTargets : collect(),
            'runGroups' => $this->showExecuteModal ? $this->runTargetGroups : collect(),
        ]);
    }

    private function isFiltered(): bool
    {
        return $this->search !== '' || $this->categoryFilter !== '' || $this->platformFilter !== '';
    }

    /** @param  Collection<int, ScriptGroup>  $groups */
    private function summary(Collection $groups): string
    {
        $count = $groups->sum(fn (ScriptGroup $group): int => $group->scripts->count());
        $scripts = $count.' '.str('script')->plural($count);

        return $this->isFiltered() ? "{$scripts} match." : "{$scripts} in the library. Run one on any device on its platform.";
    }
}
