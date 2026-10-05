<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\DTOs\Inventory\SystemInventory;
use App\Events\SystemInventorySynced;
use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Stores the snapshot a system-inventory run printed, keeping earlier ones as
 * history. Output that is not the expected JSON object stores nothing.
 */
final class SyncDeviceInventory
{
    /**
     * @return bool Whether a snapshot was stored
     */
    public function __invoke(Device $device, string $output): bool
    {
        if ($device->isMonitorOnly) {
            return false;
        }

        $data = $this->inventory($output);

        if ($data === null) {
            Log::warning('inventory.system_unparseable', [
                'device_id' => $device->id,
                'output_start' => Str::limit($output, 200),
            ]);

            return false;
        }

        DB::transaction(function () use ($device, $data): void {
            $collectedAt = now();

            $device->inventories()->create([
                ...(new SystemInventory($data))->columns(),
                'collected_at' => $collectedAt,
                'data' => $data,
            ]);
            $device->forceFill(['system_inventoried_at' => $collectedAt])->save();
        });

        SystemInventorySynced::dispatch($device->id);

        return true;
    }

    /**
     * The JSON object line from the script's stdout, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    private function inventory(string $output): ?array
    {
        $line = Str::of($output)
            ->before(config('commands.stderr_separator'))
            ->explode("\n")
            ->map(fn (string $candidate): string => trim($candidate))
            ->first(fn (string $candidate): bool => Str::startsWith($candidate, '{'));

        $decoded = $line === null ? null : json_decode($line, true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
