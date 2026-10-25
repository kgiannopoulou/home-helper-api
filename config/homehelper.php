<?php

return [

    /*
    | The time zone of the household day. Scheduled jobs run on its clock
    | ("18:00" is 18:00 at home) and use its date as "today", so a bill due
    | on the 5th is added on the 5th at home, whatever the server's zone is.
    */
    'timezone' => env('HOME_TIMEZONE', 'UTC'),

    /*
    | Plant care (CheckPlantCare, hourly). Weather rules apply to plants out in
    | the weather (outdoor, balcony) and read one forecast per household for the
    | next 24 hours. Reminders that can wait (watering, fertilizer, repotting) are
    | only raised between the reminder hours at home; weather warnings any time.
    */
    'plants' => [
        'reminder_hours' => [8, 21],
        'overdue_after_days' => 2,
        // This much rain and an outdoor plant's watering is postponed
        'rain_skips_watering_mm' => 5,
        // This much rain and rain-sensitive plants should go under cover
        'heavy_rain_mm' => 20,
        'strong_wind_kmh' => 50,
        // Frost can form on leaves below this air temperature
        'frost_c' => 2,
        // Hot or cool days change how much an outdoor plant needs
        'hot_c' => 30,
        'cool_c' => 15,
        'forecast_cache_minutes' => 30,
        // A weather warning the household dealt with isn't raised again this soon
        'weather_cooldown_hours' => 12,
    ],

];
