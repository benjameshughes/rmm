<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AuditLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Holds scalars only, so changed values, commands and emails never reach the socket.
 */
final class AuditLogged implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;

    public readonly int $auditLogId;

    public readonly string $action;

    public function __construct(AuditLog $auditLog)
    {
        $this->auditLogId = $auditLog->id;
        $this->action = $auditLog->action->value;
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('audit')];
    }

    /** @return array{auditLogId: int, action: string} */
    public function broadcastWith(): array
    {
        return [
            'auditLogId' => $this->auditLogId,
            'action' => $this->action,
        ];
    }
}
