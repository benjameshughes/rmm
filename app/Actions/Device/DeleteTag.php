<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Events\DeviceRegrouped;
use App\Models\Device;
use App\Models\Tag;
use App\Queries\StaffAccountQueries;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a tag takes it off every PC that wore it, so each is regrouped:
 * staff accounts that reached a PC only through the tag are removed from it.
 */
final readonly class DeleteTag
{
    public function __construct(
        private StaffAccountQueries $staffAccounts,
    ) {}

    public function __invoke(Tag $tag): void
    {
        $previous = DB::transaction(function () use ($tag): array {
            $previous = $tag->devices()->get()
                ->mapWithKeys(fn (Device $device): array => [$device->id => $this->staffAccounts->accountIdsTargeting($device)->all()])
                ->all();

            $tag->delete();

            return $previous;
        });

        Device::query()->whereKey(array_keys($previous))->get()
            ->each(fn (Device $device) => DeviceRegrouped::dispatch($device, $previous[$device->id]));
    }
}
