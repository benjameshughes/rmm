<?php

declare(strict_types=1);

namespace App\Actions\Software;

use App\Events\SoftwareInventorySynced;
use App\Models\Device;
use App\Models\DeviceSoftware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Replaces a device's software inventory with the packages the winget-inventory
 * script printed. Output that is not the expected JSON leaves the inventory as it was.
 */
final class SyncDeviceSoftware
{
    /**
     * @return bool Whether the inventory was replaced
     */
    public function __invoke(Device $device, string $output): bool
    {
        if ($device->isMonitorOnly) {
            return false;
        }

        $packages = $this->packages($output);

        if ($packages === null) {
            Log::warning('software.inventory_unparseable', [
                'device_id' => $device->id,
                'output_start' => Str::limit($output, 200),
            ]);

            return false;
        }

        DB::transaction(function () use ($device, $packages): void {
            $seenAt = now();

            $packages
                ->map(fn (array $package): array => [...$package, 'device_id' => $device->id, 'last_seen_at' => $seenAt, 'created_at' => $seenAt, 'updated_at' => $seenAt])
                ->values()
                ->chunk(500)
                ->each(fn (Collection $rows) => DeviceSoftware::query()->upsert(
                    $rows->values()->all(),
                    ['device_id', 'package_id'],
                    ['name', 'installed_version', 'latest_version', 'is_update_available', 'source', 'last_seen_at', 'updated_at'],
                ));

            $device->software()->whereNotIn('package_id', $packages->keys()->all())->delete();
            $device->forceFill(['software_inventoried_at' => $seenAt])->save();
        });

        SoftwareInventorySynced::dispatch($device->id, $packages->count());

        return true;
    }

    /**
     * The JSON array line from the script's stdout, keyed by package ID, or null when there is none.
     *
     * @return Collection<string, array{package_id: string, name: string, installed_version: ?string, latest_version: ?string, is_update_available: bool, source: ?string}>|null
     */
    private function packages(string $output): ?Collection
    {
        $line = Str::of($output)
            ->before(config('commands.stderr_separator'))
            ->explode("\n")
            ->map(fn (string $candidate): string => trim($candidate))
            ->first(fn (string $candidate): bool => Str::startsWith($candidate, '['));

        $decoded = $line === null ? null : json_decode($line, true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        return collect($decoded)
            ->filter(fn (mixed $package): bool => is_array($package) && is_string($package['id'] ?? null) && trim($package['id']) !== '')
            ->map(fn (array $package): array => [
                'package_id' => $this->limited($package['id']),
                'name' => $this->limited($this->filledString($package['name'] ?? null) ?? $package['id']),
                'installed_version' => $this->limitedOrNull($package['installed_version'] ?? null),
                'latest_version' => $this->limitedOrNull($package['latest_version'] ?? null),
                'is_update_available' => (bool) ($package['is_update_available'] ?? false),
                'source' => $this->limitedOrNull($package['source'] ?? null),
            ])
            ->keyBy('package_id');
    }

    private function filledString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function limitedOrNull(mixed $value): ?string
    {
        $string = $this->filledString($value);

        return $string === null ? null : $this->limited($string);
    }

    private function limited(string $value): string
    {
        return Str::limit(trim($value), 255, '');
    }
}
