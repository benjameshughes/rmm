<?php

declare(strict_types=1);

namespace App\Livewire\DeviceGroups;

use App\Models\DeviceGroup;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Index extends Component
{
    public string $name = '';

    public string $description = '';

    public string $color = 'zinc';

    public bool $showCreateModal = false;

    public ?int $editingId = null;

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:device_groups,name'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['required', 'string', 'max:20'],
        ]);

        DeviceGroup::create([
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
        ]);

        $this->reset('name', 'description', 'color', 'showCreateModal');
        $this->color = 'zinc';
    }

    public function edit(DeviceGroup $group): void
    {
        $this->editingId = $group->id;
        $this->name = $group->name;
        $this->description = $group->description ?? '';
        $this->color = $group->color ?? 'zinc';
        $this->showCreateModal = true;
    }

    public function update(): void
    {
        abort_unless($this->editingId !== null, 422);

        $group = DeviceGroup::findOrFail($this->editingId);

        $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:device_groups,name,'.$group->id],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['required', 'string', 'max:20'],
        ]);

        $group->update([
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
        ]);

        $this->resetForm();
    }

    public function delete(DeviceGroup $group): void
    {
        $group->delete();
    }

    public function resetForm(): void
    {
        $this->reset('name', 'description', 'editingId', 'showCreateModal');
        $this->color = 'zinc';
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $groups = DeviceGroup::query()
            ->withCount('devices')
            ->orderBy('name')
            ->get();

        return view('livewire.device-groups.index', [
            'groups' => $groups,
            'colors' => ['zinc', 'green', 'blue', 'amber', 'red', 'purple'],
        ]);
    }
}
