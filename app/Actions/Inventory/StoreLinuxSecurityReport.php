<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\ScriptPlatform;
use App\Events\SystemInventorySynced;
use App\Models\Device;
use Illuminate\Support\Facades\DB;

/**
 * Stores the security report a Linux agent sends every few hours as an
 * inventory snapshot, under linux_security, keeping earlier ones as history.
 * Windows keeps its own inventory, so a report from one is ignored.
 */
final class StoreLinuxSecurityReport
{
    /**
     * @param  array<string, mixed>  $report
     * @return bool Whether a snapshot was stored
     */
    public function __invoke(Device $device, array $report): bool
    {
        if ($device->platform() !== ScriptPlatform::Linux) {
            return false;
        }

        DB::transaction(function () use ($device, $report): void {
            $collectedAt = now();

            $device->inventories()->create([
                'collected_at' => $collectedAt,
                'data' => ['linux_security' => $report],
            ]);
            $device->forceFill(['system_inventoried_at' => $collectedAt])->save();
        });

        SystemInventorySynced::dispatch($device->id);

        return true;
    }
}
