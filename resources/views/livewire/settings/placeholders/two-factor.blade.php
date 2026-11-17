<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Two Factor Authentication')" :subheading="__('Manage your two-factor authentication settings')">
        <flux:skeleton.group animate="shimmer" class="my-6 w-full space-y-6">
            <flux:skeleton class="h-6 w-20 rounded-full" />
            <flux:skeleton.line />
            <flux:skeleton.line class="w-3/4" />
            <flux:skeleton class="h-10 w-36" />
        </flux:skeleton.group>
    </x-settings.layout>
</section>
