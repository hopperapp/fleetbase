<?php

return [
    'api' => [
        'routing' => [
            'prefix' => 'rides',
            'internal_prefix' => 'int',
        ],
    ],
    'bidding' => [
        'ttl_minutes' => 10,           // Bid expires after 10 minutes
        'max_bids_per_ride' => 20,     // Maximum bids per ride
        'driver_radius_meters' => 10000, // 10km radius for driver discovery
    ],
    'pricing' => [
        'default_currency' => 'YER',
    ],
];
