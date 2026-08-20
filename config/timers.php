<?php

return [


    'work' => env('TIMER_WORK', 60),

    'action' => env('TIMER_ACTION', 180),

    'travel' => env('TIMER_TRAVEL', 900),

    'talent_duration' => env('TIMER_TALENT_DURATION', 1800), 
    'talent_cooldown' => env('TIMER_TALENT_COOLDOWN', 10800), 

    'study' => env('TIMER_STUDY', 1200),

    'conflict' => env('TIMER_CONFLICT', 3600),

    'conflict_miss' => env('TIMER_CONFLICT_MISS', 900),

    'hospital' => env('TIMER_HOSPITAL', 3600),

    'protection_min' => env('TIMER_PROTECTION_MIN', 7200),

    'protection_max' => env('TIMER_PROTECTION_MAX', 10800),



    'degree_cycles' => [
        'finance' => 20,
        'law' => 30,
        'medicine' => 20,
        'engineering' => 10,
        'business' => 30,
    ],

    'police_training_cycles' => 30,

    'customs_training_cycles' => 10,

    'max_tuition' => 75000,

    'default_tuition' => 5000,
];
