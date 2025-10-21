<?php
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'lztfortnite',
        'user' => 'lztfn_user',
        'pass' => 'F7xdLmZN7Gnj8xrZ',
        'charset' => 'utf8mb4',
    ],
    'api' => [
        'base_url' => 'https://prod-api.lzt.market',
        'endpoint' => '/fortnite',
        'default_filters' => [
            'change_email' => 'yes',
            'smin' => 50,
        ],
        'timeout' => 300,
    ],
    'fetch' => [
        // Interval between API pulls when the worker loop is running.
        'interval_seconds' => 600,
        // Optional max pages safeguard; null = fetch every page the API offers.
        'max_pages' => null,
        // Delay between paginated requests in microseconds to respect rate limits.
        'page_delay_microseconds' => 250000,
    ],
];
