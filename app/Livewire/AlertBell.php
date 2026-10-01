<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\AlertStatus;
use App\Models\Alert;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
final class AlertBell extends Component
{
    public function render(): View
    {
        $count = Alert::query()
            ->where('status', AlertStatus::Triggered)
            ->count();

        return view('livewire.alert-bell', ['count' => $count]);
    }
}
