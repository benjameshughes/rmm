<?php

declare(strict_types=1);

use App\Models\Device;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the plaintext devices.api_key column with a SHA-256 hash plus an
 * encrypted, single-use copy that is only held until the agent claims it.
 *
 * Existing keys are hashed in place. Devices that already hold a key are
 * marked as claimed, so their running agents keep authenticating and the
 * plaintext is discarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('api_key_hash', 64)->nullable()->unique()->after('hardware_fingerprint');
            $table->text('pending_api_key')->nullable()->after('api_key_hash');
            $table->timestamp('api_key_issued_at')->nullable()->after('pending_api_key');
            $table->timestamp('api_key_claimed_at')->nullable()->after('api_key_issued_at');
        });

        DB::table('devices')
            ->whereNotNull('api_key')
            ->select(['id', 'api_key', 'updated_at'])
            ->lazyById()
            ->each(function (object $device): void {
                DB::table('devices')->where('id', $device->id)->update([
                    'api_key_hash' => Device::hashApiKey($device->api_key),
                    'api_key_issued_at' => $device->updated_at ?? now(),
                    'api_key_claimed_at' => now(),
                ]);
            });

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropUnique('devices_api_key_unique');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('api_key');
        });
    }

    /**
     * Hashed keys cannot be reversed, so only keys still awaiting collection are
     * restored. Every other device will need to be re-enrolled after rolling back.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('api_key')->nullable()->unique()->after('hardware_fingerprint');
        });

        DB::table('devices')
            ->whereNotNull('pending_api_key')
            ->select(['id', 'pending_api_key'])
            ->lazyById()
            ->each(function (object $device): void {
                DB::table('devices')->where('id', $device->id)->update([
                    'api_key' => Crypt::decryptString($device->pending_api_key),
                ]);
            });

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropUnique('devices_api_key_hash_unique');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['api_key_hash', 'pending_api_key', 'api_key_issued_at', 'api_key_claimed_at']);
        });
    }
};
