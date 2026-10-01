<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Script\SyncSystemScripts;
use Illuminate\Database\Seeder;

final class ScriptSeeder extends Seeder
{
    public function run(SyncSystemScripts $sync): void
    {
        $sync();
    }
}
