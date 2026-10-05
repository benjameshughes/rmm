@props(['users'])

<div class="grid gap-6 lg:grid-cols-2">
    <flux:card class="space-y-4">
        <flux:heading size="sm">Local administrators</flux:heading>
        <x-inventory.table :table="$users->localAdmins()" name="local-admins" empty="The Administrators group could not be read." />
    </flux:card>

    <flux:card class="space-y-4">
        <flux:heading size="sm">Signed in now</flux:heading>
        <x-inventory.table :table="$users->loggedOn()" name="logged-on" empty="Nobody is signed in." />
    </flux:card>

    <flux:card class="space-y-4">
        <flux:heading size="sm">Local users</flux:heading>
        <x-inventory.table :table="$users->localUsers()" name="local-users" />
    </flux:card>

    <flux:card class="space-y-4">
        <flux:heading size="sm">Profiles</flux:heading>
        <x-inventory.table :table="$users->profiles()" name="profiles" />
    </flux:card>
</div>
