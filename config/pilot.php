<?php

declare(strict_types=1);

return [

    'currency' => 'CAD',

    'languages' => ['en', 'pa-Latn'],

    /*
    | Grace after a meal cutoff during which a request that already arrived
    | on time can still be confirmed. A first message after the cutoff is late.
    */
    'default_grace_minutes' => 10,

    'schedule_horizon_days' => 21,

    'clarify_both_modules' => 'Place a pickup order or manage your tiffin?',

];
