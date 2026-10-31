<?php

// Historical ranges in minutes. Package inclusions come from the live catalogue.
return [
    'packages' => [
        'partial_grooming' => ['sizes' => ['small' => [45, 60], 'medium' => [60, 75], 'large' => [75, 90], 'extra_large' => [90, 105]]],
        'regular_dog_grooming' => ['cuts' => [
            'summer_cut' => ['small' => [75, 75], 'medium' => [75, 90], 'large' => [90, 105], 'extra_large' => [105, 120]],
            'kalbo' => ['small' => [75, 75], 'medium' => [75, 90], 'large' => [90, 105], 'extra_large' => [105, 120]],
            'semi_kalbo' => ['small' => [75, 90], 'medium' => [90, 90], 'large' => [105, 120], 'extra_large' => [120, 120]],
            'regular_trim' => ['small' => [90, 90], 'medium' => [90, 105], 'large' => [120, 120], 'extra_large' => [120, 150]],
        ]],
        'deluxe_dog_grooming' => ['cuts' => [
            'puppy_cut' => ['small' => [90, 105], 'medium' => [105, 120], 'large' => [120, 150], 'extra_large' => [150, 180]],
            'custom_hairstyle' => ['small' => [120, 150], 'medium' => [135, 180], 'large' => [150, 210], 'extra_large' => [180, 240]],
        ]],
        'bath_and_go' => ['sizes' => ['small' => [45, 45], 'medium' => [60, 60], 'large' => [75, 75], 'extra_large' => [90, 90]]],
        'cat_full_grooming' => ['sizes' => ['small' => [90, 90], 'medium' => [105, 105]]],
    ],
    'ala_carte' => ['nail_clipping' => [10, 15], 'ear_cleaning' => [10, 15], 'facial_trimming' => [15, 30], 'anal_sac_draining' => [15, 20], 'tooth_brushing' => [5, 10]],
];
