<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Overrides only `paths`: the package default is `resource_path('js/pages')`
    | (lowercase), which happens to resolve on a case-insensitive filesystem
    | (Windows/macOS, and any Docker bind mount from one) because the real
    | directory is `resources/js/Pages`. On a case-sensitive filesystem — any
    | native Linux disk, including GitHub Actions runners — it silently
    | resolves to nothing, and `assertInertia()->component(...)` fails every
    | page assertion with "does not exist" even though rendering works fine.
    |
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/Pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

];
