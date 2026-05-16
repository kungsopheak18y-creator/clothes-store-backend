<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://localhost:3000',
        'https://clothes-store-frontend-beta.vercel.app',
        'https://clothes-store-frontend-qco2xlyrf-kungsopheak18y-1095s-projects.vercel.app',
    ],

    'allowed_origins_patterns' => ['https://.*\.vercel\.app'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];