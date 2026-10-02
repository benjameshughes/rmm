<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\BellNotification;
use App\Models\Alert;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

final class AlertBell extends Component
{
    #[Locked]
    public int $userId;

    /**
     * The layout renders a bell for desktop and mobile; only one of them toasts, or every alert pops twice.
     */
    #[Locked]
    public bool $showsToasts = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Alert::class);
        $this->userId = auth()->id();
    }

    #[On('echo-private:App.Models.User.{userId},.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated')]
    public function notificationReceived(array $event): void
    {
        $notification = auth()->user()->notifications()->find($event['id'] ?? null);

        if (! $this->showsToasts || $notification === null) {
            return;
        }

        $bellNotification = BellNotification::fromDatabase($notification);

        Flux::toast(text: $bellNotification->body, heading: $bellNotification->title, variant: $bellNotification->level->toastVariant());
    }

    public function open(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->findOrFail($notificationId);
        $notification->markAsRead();

        $this->redirect(BellNotification::fromDatabase($notification)->url, navigate: true);
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
    }

    public function render(): View
    {
        $user = auth()->user();

        $notifications = $user->notifications()
            ->latest()
            ->limit(config('alerts.bell_limit'))
            ->get()
            ->map(fn (DatabaseNotification $notification): BellNotification => BellNotification::fromDatabase($notification));

        return view('livewire.alert-bell', [
            'notifications' => $notifications,
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }
}
