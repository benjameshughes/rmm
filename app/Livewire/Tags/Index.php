<?php

declare(strict_types=1);

namespace App\Livewire\Tags;

use App\Models\Tag;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Index extends Component
{
    public string $name = '';

    public string $color = 'zinc';

    public bool $showCreateModal = false;

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:tags,name'],
            'color' => ['required', 'string', 'max:20'],
        ]);

        Tag::create([
            'name' => $this->name,
            'color' => $this->color,
        ]);

        $this->reset('name', 'showCreateModal');
        $this->color = 'zinc';
    }

    public function delete(Tag $tag): void
    {
        $tag->delete();
    }

    public function render(): View
    {
        $tags = Tag::query()
            ->withCount('devices')
            ->orderBy('name')
            ->get();

        return view('livewire.tags.index', [
            'tags' => $tags,
            'colors' => ['zinc', 'green', 'blue', 'amber', 'red', 'purple'],
        ]);
    }
}
