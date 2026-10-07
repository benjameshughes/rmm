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
    | Installing By ID
    |--------------------------------------------------------------------------
    |
    | A package ID typed in for a bulk install must match the same pattern the
    | winget-install script checks, so a bad ID is refused before it is
    | queued on every device. The picker suggests this many known IDs.
    |
    */

    'package_id_pattern' => '/^[A-Za-z0-9][A-Za-z0-9._+-]*$/',
    'package_id_max_length' => 128,
    'install_suggestions' => 8,

    /*
    |--------------------------------------------------------------------------
    | Hidden Runtimes
    |--------------------------------------------------------------------------
    |
    | Microsoft runtimes and frameworks that other apps depend on: removing
    | them breaks those apps, so they are left out of the software lists.
    | Patterns are SQL LIKE matches on the package ID or the name.
    |
    */

    'hidden' => [
        'package_ids' => [
            'Microsoft.DotNet.%',
            'Microsoft.VCRedist.%',
            '%Microsoft.VCLibs%',
            '%Microsoft.UI.Xaml%',
            '%Microsoft.NET.Native.%',
            '%Microsoft.WindowsAppRuntime%',
            '%Microsoft.Advertising.Xaml%',
            '%Microsoft.Services.Store.Engagement%',
            '%Microsoft.LanguageExperiencePack%',
        ],
        'names' => [
            'Microsoft Visual C++%',
            'Microsoft Visual J#%',
            'Microsoft Update Health Tools',
            'Microsoft Windows Application Compatibility Fix Database',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    */

    'fleet_per_page' => 25,
    'device_per_page' => 25,

];
