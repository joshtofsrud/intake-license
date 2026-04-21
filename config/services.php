<?php

return [
    'twilio' => [
        'driver' => env('TWILIO_DRIVER', 'null'),
        'sid'    => env('TWILIO_SID'),
        'token'  => env('TWILIO_TOKEN'),
        'from'   => env('TWILIO_FROM'),
    ],
];
