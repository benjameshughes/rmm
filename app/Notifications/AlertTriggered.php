<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued so a slow socket or a big team never holds up metric ingestion.
 */
final class AlertTriggered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Alert $alert,
    ) {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array{alertId: int, deviceId: int, hostname: string, severity: string, condition: string, message: string} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'alertId' => $this->alert->id,
            'deviceId' => $this->alert->device_id,
            'hostname' => $this->alert->device->hostname,
            'severity' => $this->alert->severity->value,
            'condition' => $this->alert->conditionLabel(),
            'message' => $this->alert->message,
        ];
    }

    /**
     * Scalars only, so hostnames and values stay off the socket; the browser reads the stored copy.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'alertId' => $this->alert->id,
            'severity' => $this->alert->severity->value,
        ]);
    }
}
