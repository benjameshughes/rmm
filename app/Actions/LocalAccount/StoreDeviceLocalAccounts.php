<?php

declare(strict_types=1);

namespace App\Actions\LocalAccount;

use App\Enums\ScriptPlatform;
use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a Windows PC's local accounts with the ones its agent just
 * reported, keyed by SID, so a renamed account keeps its row. Accounts the
 * agent no longer reports are gone from the PC and are deleted. A report
 * from a Linux agent is ignored: those agents are read-only and never send one.
 */
final class StoreDeviceLocalAccounts
{
    /**
     * @param  array<int, array{name: string, full_name?: ?string, sid: string, is_enabled: bool, is_admin?: ?bool, last_logon_at?: ?string, is_signed_in?: ?bool}>  $accounts
     * @return bool Whether the accounts were stored
     */
    public function __invoke(Device $device, array $accounts): bool
    {
        if ($device->platform() !== ScriptPlatform::Windows) {
            return false;
        }

        $rows = collect($accounts)
            ->map(fn (array $account): array => [
                'device_id' => $device->id,
                'sid' => mb_strtoupper($account['sid']),
                'username' => $account['name'],
                'full_name' => $account['full_name'] ?? null,
                'is_enabled' => (bool) $account['is_enabled'],
                'is_admin' => $account['is_admin'] ?? null,
                'is_signed_in' => $account['is_signed_in'] ?? null,
                'last_logon_at' => isset($account['last_logon_at']) ? Carbon::parse($account['last_logon_at']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->unique('sid');

        DB::transaction(function () use ($device, $rows): void {
            $device->localAccounts()->whereNotIn('sid', $rows->pluck('sid'))->delete();

            if ($rows->isNotEmpty()) {
                $device->localAccounts()->upsert(
                    $rows->values()->all(),
                    uniqueBy: ['device_id', 'sid'],
                    update: ['username', 'full_name', 'is_enabled', 'is_admin', 'is_signed_in', 'last_logon_at', 'updated_at'],
                );
            }
        });

        return true;
    }
}
