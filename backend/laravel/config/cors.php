<?php

/*
 * CORS for /api/*: only the storefront origin (FRONTEND_URL), with
 * credentials so the HttpOnly cart cookie travels. Owned by the
 * commerce builder.
 */
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => true,

];
