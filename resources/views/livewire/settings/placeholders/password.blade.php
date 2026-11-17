<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
        <flux:skeleton.group animate="shimmer" class="my-6 w-full space-y-6">
            <flux:skeleton class="h-16 w-full" />
            <flux:skeleton class="h-16 w-full" />
            <flux:skeleton class="h-16 w-full" />
            <flux:skeleton class="h-10 w-20" />
        </flux:skeleton.group>
    </x-settings.layout>
</section>
