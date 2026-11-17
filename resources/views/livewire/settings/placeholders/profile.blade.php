<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <flux:skeleton.group animate="shimmer" class="my-6 w-full space-y-6">
            <flux:skeleton class="h-16 w-full" />
            <flux:skeleton class="h-16 w-full" />
            <flux:skeleton class="h-10 w-20" />
        </flux:skeleton.group>
    </x-settings.layout>
</section>
