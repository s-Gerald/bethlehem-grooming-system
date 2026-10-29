<?php

return [
    // Consumed by Laravel's TrustProxies middleware after configuration loads.
    // Empty by default: direct clients cannot choose their source IP via headers.
    'proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('AUTH_TRUSTED_PROXIES', ''))),
        fn ($proxy) => $proxy !== '' && ! in_array($proxy, ['*', '**'], true))),
];
