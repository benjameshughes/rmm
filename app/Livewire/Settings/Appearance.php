<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
final class Appearance extends Component
{
    public function placeholder(): View
    {
        return view('livewire.settings.placeholders.appearance');
    }
}
