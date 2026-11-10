<?php

/*
| Read by Laravel's TrustProxies middleware (on by default). Behind a load
| balancer or reverse proxy, list its addresses so request()->ip(), which the
| rate limits count by, and https:// URLs come from the X-Forwarded-* headers:
|
|   TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12      or  TRUSTED_PROXIES=*
|
| Leave it empty when the app faces the internet directly: then nobody can
| pick their own IP address by sending X-Forwarded-For.
*/

$proxies = (string) env('TRUSTED_PROXIES', '');

return [
    'proxies' => match ($proxies) {
        '' => null,
        '*' => '*',
        default => array_map('trim', explode(',', $proxies)),
    },
];
