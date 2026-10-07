@props(['device', 'state', 'problem'])

<div {{ $attributes->class('flex flex-wrap items-center gap-x-3 gap-y-1 py-2') }} data-backup-problem-row="{{ $state->value }}">
    <flux:icon :name="$state->icon()" @class(['size-5 shrink-0', 'text-red-500' => $state === App\Enums\BackupState::Failed, 'text-amber-500' => $state !== App\Enums\BackupState::Failed]) />
    <div class="min-w-0 flex-1">
        <a href="{{ route('devices.backups', $device) }}" wire:navigate class="truncate text-sm font-medium text-zinc-800 hover:underline dark:text-white">{{ $device->hostname }}</a>
        <flux:text size="xs" class="truncate">{{ Str::ucfirst($problem) }}</flux:text>
    </div>
    <flux:badge size="sm" :color="$state->color()">{{ $state->label() }}</flux:badge>
</div>
