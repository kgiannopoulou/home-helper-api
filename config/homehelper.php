<?php

return [

    /*
    | The time zone of the household day. Scheduled jobs run on its clock
    | ("18:00" is 18:00 at home) and use its date as "today", so a bill due
    | on the 5th is added on the 5th at home, whatever the server's zone is.
    */
    'timezone' => env('HOME_TIMEZONE', 'UTC'),

];
