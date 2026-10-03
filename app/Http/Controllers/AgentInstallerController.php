<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

final class AgentInstallerController
{
    public function download(): Response
    {
        return $this->installer(config('scripts.installer'), 'agent-install.ps1');
    }

    public function downloadLinux(): Response
    {
        return $this->installer(config('scripts.linux_installer'), 'agent-install.sh');
    }

    private function installer(string $path, string $filename): Response
    {
        $script = str_replace('{BASE_URL}', url('/'), File::get($path));

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
