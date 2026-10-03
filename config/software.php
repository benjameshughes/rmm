<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Software Inventory
    |--------------------------------------------------------------------------
    |
    | The winget-inventory system script prints every installed package as
    | JSON; its result replaces the device's inventory. After any script in
    | refresh_after_slugs finishes, a fresh inventory is queued so the pages
    | show the change. The inventory script is never in that list, so a
    | refresh never queues another refresh.
    |
    */

    'inventory_slug' => 'winget-inventory',

    'refresh_after_slugs' => [
        'winget-install',
        'winget-upgrade',
        'winget-uninstall',
    ],

    /*
    |--------------------------------------------------------------------------
    | Winget Sources
    |--------------------------------------------------------------------------
    |
    | Only packages from this source can be upgraded with winget by ID; ARP
    | and MSIX entries have no source and can only be uninstalled.
    |
    */

    'upgradable_source' => 'winget',

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    */

    'fleet_per_page' => 25,
    'device_per_page' => 25,

];
