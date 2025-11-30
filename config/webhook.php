<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Webhook Secret
    |--------------------------------------------------------------------------
    |
    | This secret is used to validate webhook signatures from payment providers.
    | Set this in your .env file as WEBHOOK_SECRET.
    |
    */

    'secret' => env('WEBHOOK_SECRET', null),
];

