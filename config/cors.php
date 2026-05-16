<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://clothes-store-frontend-sage.vercel.app',
        'https://clothes-store-frontend-gt8qhpm20-kungsopheak18y-1095s-projects.vercel.app',
        'http://localhost:5173',
    ],
    'allowed_origins_patterns' => ['#^https://.*\.vercel\.app$#'],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];