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

    /**
     * The Linux installer carries the Netdata installer inline, so a monitor-only
     * machine, which can never be sent a script, gets Netdata at enrolment.
     */
    public function downloadLinux(): Response
    {
        return $this->installer(config('scripts.linux_installer'), 'agent-install.sh', [
            '{NETDATA_INSTALLER}' => rtrim(File::get(config('scripts.linux_netdata_installer'))),
        ]);
    }

    /** @param array<string, string> $inserts */
    private function installer(string $path, string $filename, array $inserts = []): Response
    {
        $script = strtr(File::get($path), ['{BASE_URL}' => url('/'), ...$inserts]);

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
