<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Kinds of life-admin item. */
enum AdminKind: string
{
    use HasValues;

    case Bill = 'bill';
    case Appointment = 'appointment';
    case Renewal = 'renewal';
    case Other = 'other';
}
