<?php

return [
    // Editable grooming catalogue limits; clinic pricing has separate business rules.
    'price_limits' => [
        'package' => ['minimum' => 1, 'maximum' => 20000],
        'ala_carte' => ['minimum' => 1, 'maximum' => 5000],
    ],
    // Static kinds/supported sizes and official restoration/legacy defaults.
    // Persisted catalogue values remain authoritative for current live pricing.
    'services' => [
        'partial_grooming' => [
            'kind' => 'package',
            'starting_sizes' => ['large', 'extra_large'],
            'prices' => [
                'small' => '400.00',
                'medium' => '500.00',
                'large' => '600.00',
                'extra_large' => '700.00',
            ],
            'default' => '500.00',
        ],
        'regular_dog_grooming' => [
            'kind' => 'package',
            'starting_sizes' => ['large', 'extra_large'],
            'prices' => [
                'small' => '550.00',
                'medium' => '650.00',
                'large' => '850.00',
                'extra_large' => '1050.00',
            ],
            'default' => '650.00',
        ],
        'deluxe_dog_grooming' => [
            'kind' => 'package',
            'starting_sizes' => ['large', 'extra_large'],
            'prices' => [
                'small' => '650.00',
                'medium' => '750.00',
                'large' => '1000.00',
                'extra_large' => '1200.00',
            ],
            'default' => '750.00',
        ],
        'bath_and_go' => [
            'kind' => 'package',
            'starting_sizes' => ['large', 'extra_large'],
            'prices' => [
                'small' => '450.00',
                'medium' => '550.00',
                'large' => '650.00',
                'extra_large' => '750.00',
            ],
            'default' => '550.00',
        ],
        'cat_full_grooming' => [
            'kind' => 'package',
            'prices' => [
                'small' => '500.00',
                'medium' => '600.00',
            ],
            'default' => '550.00',
        ],
        'nail_clipping' => [
            'kind' => 'ala_carte',
            'minimum' => '50.00',
            'maximum' => '100.00',
        ],
        'ear_cleaning' => [
            'kind' => 'ala_carte',
            'default' => '150.00',
            'starting_price' => true,
        ],
        'facial_trimming' => [
            'kind' => 'ala_carte',
            'default' => '150.00',
        ],
        'anal_sac_draining' => [
            'kind' => 'ala_carte',
            'default' => '150.00',
        ],
        'tooth_brushing' => [
            'kind' => 'ala_carte',
            'default' => '100.00',
            'starting_price' => true,
        ],
    ],
];
