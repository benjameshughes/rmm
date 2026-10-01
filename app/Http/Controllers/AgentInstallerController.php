<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

final class AgentInstallerController
{
    public function download(): Response
    {
        $script = str_replace('{BASE_URL}', url('/'), File::get(config('scripts.installer')));

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="agent-install.ps1"',
        ]);
    }
}
