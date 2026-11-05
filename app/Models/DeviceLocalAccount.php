<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DeviceLocalAccountFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A local Windows account as the PC's agent last reported it. The rows are
 * replaced on every report, keyed by SID. A null is_admin or is_signed_in
 * means the agent could not read it, not no.
 */
final class DeviceLocalAccount extends Model
{
    /** @use HasFactory<DeviceLocalAccountFactory> */
    use HasFactory;

    protected $fillable = ['sid', 'username', 'full_name', 'is_enabled', 'is_admin', 'is_signed_in', 'last_logon_at'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_admin' => 'boolean',
            'is_signed_in' => 'boolean',
            'last_logon_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Administrator, Guest, DefaultAccount or WDAGUtilityAccount, by RID.
     */
    protected function isBuiltIn(): Attribute
    {
        return Attribute::get(fn (): bool => in_array(Str::afterLast($this->sid, '-'), config('local_accounts.built_in_rids'), strict: true));
    }

    /**
     * Only an account known not to be signed in may be deleted.
     */
    protected function isSignedOut(): Attribute
    {
        return Attribute::get(fn (): bool => $this->is_signed_in === false);
    }
}
