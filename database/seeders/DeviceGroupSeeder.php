<?php

namespace Database\Seeders;

use App\Models\DeviceGroup;
use App\Models\Tag;
use Illuminate\Database\Seeder;

class DeviceGroupSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['name' => 'Servers', 'description' => 'Production and development servers', 'color' => 'blue'],
            ['name' => 'Workstations', 'description' => 'Employee desktops and laptops', 'color' => 'green'],
            ['name' => 'Laptops', 'description' => 'Mobile devices', 'color' => 'purple'],
            ['name' => 'Development', 'description' => 'Development and staging machines', 'color' => 'amber'],
        ])->each(fn (array $group) => DeviceGroup::create($group));

        collect([
            ['name' => 'critical', 'color' => 'red'],
            ['name' => 'windows', 'color' => 'blue'],
            ['name' => 'linux', 'color' => 'green'],
            ['name' => 'needs-update', 'color' => 'amber'],
            ['name' => 'monitored', 'color' => 'purple'],
        ])->each(fn (array $tag) => Tag::create($tag));
    }
}
