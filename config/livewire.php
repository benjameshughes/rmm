<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Page Layout
    |--------------------------------------------------------------------------
    |
    | Full-page components without their own #[Layout] render into this
    | view. Livewire 4 defaults to layouts::app, but the app layout lives
    | in the components folder, as it did under Livewire 3.
    |
    */

    'component_layout' => 'components.layouts.app',

    /*
    |--------------------------------------------------------------------------
    | Make Command
    |--------------------------------------------------------------------------
    |
    | Components are a PHP class plus a Blade view, so make:livewire keeps
    | generating that pair instead of Livewire 4's single-file components.
    |
    */

    'make_command' => [
        'type' => 'class',
        'emoji' => false,
        'with' => [
            'js' => false,
            'css' => false,
            'test' => false,
        ],
    ],

];
