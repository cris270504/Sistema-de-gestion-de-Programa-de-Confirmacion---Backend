<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Lista blanca de orígenes. Se arma desde CORS_ALLOWED_ORIGINS (CSV) y, como
    // fallback, desde APP_FRONTEND_URL. Nunca '*' junto con supports_credentials.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(
        ',',
        env('CORS_ALLOWED_ORIGINS', env('APP_FRONTEND_URL', 'http://localhost:5173'))
    )))),

    // Permite previews de Vercel (https://<algo>.vercel.app) sin listarlos uno a uno.
    'allowed_origins_patterns' => array_values(array_filter(array_map('trim', explode(
        ',',
        env('CORS_ALLOWED_ORIGINS_PATTERNS', '')
    )))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];
