<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

/**
 * Local accounts, administrators, signed-in users and profiles from one system inventory.
 */
final class InventoryUsers
{
    use ReadsInventoryData;

    /**
     * @param  array<string, mixed>  $data  The inventory's users section
     */
    public function __construct(
        private readonly array $data,
    ) {}

    /**
     * Null when the Administrators group could not be read, which is not the same as empty.
     */
    public function localAdminCount(): ?int
    {
        return is_array($this->data['local_admins'] ?? null) ? $this->rows('local_admins')->count() : null;
    }

    /**
     * Who was signed in when the inventory ran; null when the sessions could not be read.
     */
    public function signedInForHumans(): ?string
    {
        if (! is_array($this->data['logged_on'] ?? null)) {
            return null;
        }

        $names = $this->rows('logged_on')
            ->map(fn (array $session): ?string => $this->text($session['name'] ?? null))
            ->filter()
            ->unique();

        return $names->isEmpty() ? 'Nobody' : $names->implode(', ');
    }

    public function localUsers(): InventoryTable
    {
        return $this->table('local_users', [
            'Name' => fn (array $user): ?string => $this->text($user['name'] ?? null),
            'Enabled' => fn (array $user): ?string => $this->yesNo($user['is_enabled'] ?? null),
            'Last logon' => fn (array $user): ?string => $this->dateTime($user['last_logon'] ?? null),
            'Password set' => fn (array $user): ?string => $this->date($user['password_last_set'] ?? null),
            'Description' => fn (array $user): ?string => $this->text($user['description'] ?? null),
        ]);
    }

    public function localAdmins(): InventoryTable
    {
        return $this->table('local_admins', [
            'Name' => fn (array $member): ?string => $this->text($member['name'] ?? null),
            'Type' => fn (array $member): ?string => $this->text($member['object_class'] ?? null),
            'Source' => fn (array $member): ?string => $this->text($member['principal_source'] ?? null),
        ]);
    }

    public function loggedOn(): InventoryTable
    {
        return $this->table('logged_on', [
            'User' => fn (array $session): ?string => $this->text($session['name'] ?? null),
            'Session' => fn (array $session): ?string => $this->text($session['session_id'] ?? null),
            'Since' => fn (array $session): ?string => $this->dateTime($session['since'] ?? null),
        ]);
    }

    public function profiles(): InventoryTable
    {
        return $this->table('profiles', [
            'Path' => fn (array $profile): ?string => $this->text($profile['path'] ?? null),
            'Last used' => fn (array $profile): ?string => $this->dateTime($profile['last_used'] ?? null),
            'Loaded' => fn (array $profile): ?string => $this->yesNo($profile['is_loaded'] ?? null),
        ]);
    }
}
