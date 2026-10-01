<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Raw agent requests were stored alongside every metric even though each
     * useful field is already extracted into its own column. Chunked so a
     * large metrics table is not locked by one long update.
     */
    public function up(): void
    {
        DB::table('device_metrics')
            ->whereNotNull('payload')
            ->select('id')
            ->chunkById(1000, fn (Collection $rows) => DB::table('device_metrics')
                ->whereIn('id', $rows->pluck('id'))
                ->update(['payload' => null]));
    }

    /**
     * The payloads are gone for good, so there is nothing to restore.
     */
    public function down(): void {}
};
