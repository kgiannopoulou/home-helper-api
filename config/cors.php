<?php

/*
| Which web pages may call the API from a browser (the native phone app
| isn't a browser and ignores CORS). Locally `*`, so the Expo web build on
| another port works. In production list the origins, e.g.
|   CORS_ALLOWED_ORIGINS=https://home.example.com
| Tokens travel in the Authorization header, not cookies, so credentials
| stay off.
*/

$origins = (string) env('CORS_ALLOWED_ORIGINS', '*');

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins === '*' ? ['*'] : array_values(array_filter(array_map('trim', explode(',', $origins)))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Request-Id', 'X-Requested-With'],

    // The phone shows the request id in its error reports; Retry-After after a 429
    'exposed_headers' => ['X-Request-Id', 'Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
