<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <flux:skeleton.group animate="shimmer" class="my-6 w-full space-y-6">
            <flux:skeleton class="h-10 w-72" />
        </flux:skeleton.group>
    </x-settings.layout>
</section>
