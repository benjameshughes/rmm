<?php

declare(strict_types=1);

namespace App\Actions\Printer;

use App\Models\DeviceCommand;
use App\Models\ZebraTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Makes the settings a capture-zebra-template run printed the one Zebra
 * template, replacing any before it. Output from a printer that is not on
 * the ZDesigner driver, settings that are not base64, or settings too big to
 * hand back to a PC as an environment variable store nothing.
 */
final class StoreZebraTemplate
{
    public function __invoke(DeviceCommand $command): ?ZebraTemplate
    {
        $result = $command->resultJson() ?? [];
        $printerName = $result['printer_name'] ?? null;
        $driverName = $result['driver_name'] ?? null;
        $settings = $result['settings_b64'] ?? null;

        $isUsable = is_string($printerName) && $printerName !== ''
            && is_string($driverName) && ZebraTemplate::isZDesignerDriver($driverName)
            && is_string($settings) && $this->isStorableSettings($settings);

        if (! $isUsable) {
            Log::warning('zebra.template_refused', [
                'device_id' => $command->device_id,
                'command_id' => $command->id,
                'driver_name' => is_string($driverName) ? Str::limit($driverName, 100) : null,
                'settings_length' => is_string($settings) ? strlen($settings) : null,
            ]);

            return null;
        }

        return DB::transaction(function () use ($command, $printerName, $driverName, $settings): ZebraTemplate {
            ZebraTemplate::query()->delete();

            return ZebraTemplate::query()->create([
                'device_id' => $command->device_id,
                'source_hostname' => $command->device->hostname,
                'printer_name' => $printerName,
                'driver_name' => $driverName,
                'settings' => $settings,
                'captured_at' => $command->completed_at ?? now(),
            ]);
        });
    }

    private function isStorableSettings(string $settings): bool
    {
        return $settings !== ''
            && strlen($settings) <= config('zebra.template_max_chars')
            && base64_decode($settings, strict: true) !== false;
    }
}
