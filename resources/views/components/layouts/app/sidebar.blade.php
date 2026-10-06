<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <div class="flex items-center justify-between">
                <a href="{{ route('dashboard') }}" class="me-5 flex items-center space-x-2 rtl:space-x-reverse" wire:navigate>
                    <x-app-logo />
                </a>

                @auth
                    <div class="hidden lg:block">
                        <livewire:alert-bell :shows-toasts="true" />
                    </div>
                @endauth
            </div>

            <flux:navlist variant="outline">
                <flux:navlist.group :heading="__('Fleet')" class="grid">
                    <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>{{ __('Dashboard') }}</flux:navlist.item>
                    <flux:navlist.item icon="server" :href="route('devices.index')" :current="request()->routeIs('devices.index', 'devices.show', 'devices.metrics', 'devices.commands', 'devices.apps', 'devices.system', 'devices.details')" wire:navigate>{{ __('Devices') }}</flux:navlist.item>
                    <flux:navlist.item icon="clock" :href="route('devices.pending')" :current="request()->routeIs('devices.pending')" wire:navigate>{{ __('Pending') }}</flux:navlist.item>
                    <flux:navlist.item icon="squares-plus" :href="route('software.index')" :current="request()->routeIs('software.*')" wire:navigate>{{ __('Software') }}</flux:navlist.item>
                    <flux:navlist.item icon="cpu-chip" :href="route('hardware.index')" :current="request()->routeIs('hardware.*')" wire:navigate>{{ __('Hardware') }}</flux:navlist.item>
                </flux:navlist.group>

                <flux:navlist.group :heading="__('Automation')" class="grid">
                    <flux:navlist.item icon="code-bracket" :href="route('scripts.index')" :current="request()->routeIs('scripts.*')" wire:navigate>{{ __('Scripts') }}</flux:navlist.item>
                    <flux:navlist.item icon="calendar" :href="route('scheduled-tasks.index')" :current="request()->routeIs('scheduled-tasks.*')" wire:navigate>{{ __('Schedules') }}</flux:navlist.item>
                </flux:navlist.group>

                <flux:navlist.group :heading="__('Monitoring')" class="grid">
                    <flux:navlist.item icon="bell-alert" :href="route('alerts.index')" :current="request()->routeIs('alerts.*', 'alert-rules.*')" wire:navigate>{{ __('Alerts') }}</flux:navlist.item>
                    @can('viewAny', App\Models\AuditLog::class)
                        <flux:navlist.item icon="shield-check" :href="route('audit.index')" :current="request()->routeIs('audit.*')" wire:navigate>{{ __('Audit Log') }}</flux:navlist.item>
                    @endcan
                </flux:navlist.group>

                <flux:navlist.group :heading="__('Setup')" class="grid">
                    <flux:navlist.item icon="rectangle-group" :href="route('device-groups.index')" :current="request()->routeIs('device-groups.*', 'tags.*')" wire:navigate>{{ __('Groups') }}</flux:navlist.item>
                    <flux:navlist.item icon="download" :href="route('devices.agent')" :current="request()->routeIs('devices.agent')" wire:navigate>{{ __('Agent') }}</flux:navlist.item>
                </flux:navlist.group>
            </flux:navlist>

            <flux:spacer />

            <!-- Desktop User Menu -->
            <flux:dropdown class="hidden lg:block" position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon:trailing="chevrons-up-down"
                />

                <flux:menu class="w-[220px]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            @auth
                <livewire:alert-bell />
            @endauth

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <x-modal.confirm />
        <livewire:commands.detail />

        <flux:toast />

        @fluxScripts
    </body>
</html>
