<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommandStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A value chosen in the UI that one command needs on the PC, such as a staff
 * password. It is kept encrypted only until the agent fetches the command,
 * which takes and deletes it. A command that finishes any other way (cancel,
 * failure, time-out) deletes it too, and the prune clears any left past
 * expires_at. It is never audited, logged or serialised.
 */
final class CommandSecret extends Model
{
    use Prunable;

    protected $fillable = ['device_command_id', 'name', 'value', 'expires_at'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(DeviceCommand::class, 'device_command_id');
    }

    public function prunable(): Builder
    {
        return self::query()
            ->where('expires_at', '<', now())
            ->orWhereHas('command', fn (Builder $commandQuery): Builder => $commandQuery
                ->whereIn('status', collect(CommandStatus::cases())->filter(fn (CommandStatus $status): bool => $status->isTerminal())->all()));
    }
}
