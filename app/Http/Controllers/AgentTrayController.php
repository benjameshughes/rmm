<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class AgentTrayController extends Controller
{
    public function download()
    {
        $appName = 'RMM Tray';
        $baseUrl = url('/');

        $placeholders = [
            '{APP_NAME}' => $appName,
            '{BASE_URL}' => $baseUrl,
        ];

        $files = [
            'package.json' => resource_path('tauri/tray-template/package.json'),
            'src/index.html' => resource_path('tauri/tray-template/src/index.html'),
            'src-tauri/tauri.conf.json' => resource_path('tauri/tray-template/src-tauri/tauri.conf.json'),
            'src-tauri/Cargo.toml' => resource_path('tauri/tray-template/src-tauri/Cargo.toml'),
            'src-tauri/src/main.rs' => resource_path('tauri/tray-template/src-tauri/src/main.rs'),
            'src-tauri/src/config.rs' => resource_path('tauri/tray-template/src-tauri/src/config.rs'),
            'src-tauri/src/agent.rs' => resource_path('tauri/tray-template/src-tauri/src/agent.rs'),
            'src-tauri/src/enrollment.rs' => resource_path('tauri/tray-template/src-tauri/src/enrollment.rs'),
            'src-tauri/src/metrics.rs' => resource_path('tauri/tray-template/src-tauri/src/metrics.rs'),
            'src-tauri/src/sysinfo.rs' => resource_path('tauri/tray-template/src-tauri/src/sysinfo.rs'),
            'src-tauri/src/storage.rs' => resource_path('tauri/tray-template/src-tauri/src/storage.rs'),
            'README.md' => resource_path('tauri/tray-template/README.md'),
            '.gitignore' => resource_path('tauri/tray-template/.gitignore'),
        ];

        $zip = new \ZipArchive;
        $tmp = tempnam(sys_get_temp_dir(), 'tauri-tray-') ?: sys_get_temp_dir().'/tauri-tray.zip';
        if (is_file($tmp)) {
            unlink($tmp);
        }
        $zip->open($tmp, \ZipArchive::CREATE);

        foreach ($files as $zipPath => $filePath) {
            if (! is_file($filePath)) {
                continue;
            }

            $contents = file_get_contents($filePath);
            // Simple placeholder replacement for text files
            $contents = str_replace(array_keys($placeholders), array_values($placeholders), $contents);
            $zip->addFromString($zipPath, $contents);
        }

        $zip->close();

        $filename = Str::slug($appName).'-scaffold.zip';

        return response()->download($tmp, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
