<?php

declare(strict_types=1);

namespace App\DTOs\Staff;

/**
 * Everything about a staff account but its password, as entered on the Staff page.
 */
final readonly class StaffAccountDetails
{
    /**
     * @param  array<int, int>  $deviceIds
     * @param  array<int, int>  $deviceGroupIds
     * @param  array<int, int>  $tagIds
     */
    public function __construct(
        public string $username,
        public string $fullName,
        public bool $isAdmin,
        public bool $isEnabled,
        public array $deviceIds = [],
        public array $deviceGroupIds = [],
        public array $tagIds = [],
    ) {}

    /** @return array{username: string, full_name: string, is_admin: bool, is_enabled: bool} */
    public function attributes(): array
    {
        return [
            'username' => $this->username,
            'full_name' => $this->fullName,
            'is_admin' => $this->isAdmin,
            'is_enabled' => $this->isEnabled,
        ];
    }
}
