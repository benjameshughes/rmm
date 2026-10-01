<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Script\SyncSystemScripts as SyncSystemScriptsAction;
use Illuminate\Console\Command;

final class SyncSystemScripts extends Command
{
    protected $signature = 'scripts:sync';

    protected $description = 'Sync the built-in system scripts from resources/scripts into the database';

    public function handle(SyncSystemScriptsAction $sync): int
    {
        $count = $sync();

        $this->components->info("Synced {$count} system scripts.");

        return self::SUCCESS;
    }
}
