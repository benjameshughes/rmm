<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AlertChanged;
use App\Models\Alert;
use App\Models\User;
use App\Notifications\AlertTriggered;
use Illuminate\Support\Facades\Notification;

final class NotifyUsersOfTriggeredAlert
{
    public function handle(AlertChanged $event): void
    {
        if (! $event->isNewlyTriggered()) {
            return;
        }

        $alert = Alert::query()->with(['device', 'alertRule'])->find($event->alertId);

        if ($alert === null) {
            return;
        }

        $recipients = User::query()->humans()->get()
            ->filter(fn (User $user): bool => $user->can('viewAny', Alert::class));

        Notification::send($recipients, new AlertTriggered($alert));
    }
}
