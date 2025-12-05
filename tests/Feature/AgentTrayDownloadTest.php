<?php

declare(strict_types=1);

it('serves a tauri tray scaffold zip', function (): void {
    $response = $this->get('/agent/tauri.zip');

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'application/zip');
});
