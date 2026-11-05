<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Device;
use App\Models\DeviceLocalAccount;
use App\Models\StaffAccount;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DeviceLocalAccountQueries
{
    public function __construct(
        private StaffAccountQueries $staffAccounts,
    ) {}

    /**
     * The lower-cased usernames of the staff accounts that target the PC.
     * Windows user names ignore case, so matching does too.
     *
     * @return Collection<int, string>
     */
    public function managedUsernames(Device $device): Collection
    {
        return StaffAccount::query()
            ->whereKey($this->staffAccounts->accountIdsTargeting($device))
            ->pluck('username')
            ->map(fn (string $username): string => Str::lower($username));
    }

    public function isManaged(DeviceLocalAccount $account): bool
    {
        return $this->managedUsernames($account->device)->contains(Str::lower($account->username));
    }

    /**
     * Each username's most recent logon on any PC, with the PC.
     *
     * @param  Collection<int, string>  $usernames
     * @return Collection<string, DeviceLocalAccount> Keyed by lower-cased username
     */
    public function latestLogons(Collection $usernames): Collection
    {
        if ($usernames->isEmpty()) {
            return collect();
        }

        /** @var EloquentCollection<int, DeviceLocalAccount> $accounts */
        $accounts = DeviceLocalAccount::query()
            ->whereIn(DB::raw('lower(username)'), $usernames->map(fn (string $username): string => Str::lower($username)))
            ->whereNotNull('last_logon_at')
            ->with('device:id,hostname')
            ->latest('last_logon_at')
            ->get();

        return $accounts->toBase()
            ->unique(fn (DeviceLocalAccount $account): string => Str::lower($account->username))
            ->keyBy(fn (DeviceLocalAccount $account): string => Str::lower($account->username));
    }
}
