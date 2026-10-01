<?php

declare(strict_types=1);

namespace App\Livewire\Scripts;

use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Models\Script;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
final class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $categoryFilter = '';

    public string $platformFilter = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatingPlatformFilter(): void
    {
        $this->resetPage();
    }

    public function delete(Script $script): void
    {
        abort_unless(! $script->is_system, 403);
        $script->delete();
    }

    public function render(): View
    {
        $scripts = Script::query()
            ->when($this->search !== '', fn (Builder $q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->when($this->categoryFilter !== '', fn (Builder $q) => $q->where('category', $this->categoryFilter))
            ->when($this->platformFilter !== '', fn (Builder $q) => $q->where('platform', $this->platformFilter))
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.scripts.index', [
            'scripts' => $scripts,
            'categories' => ScriptCategory::cases(),
            'platforms' => ScriptPlatform::cases(),
        ]);
    }
}
