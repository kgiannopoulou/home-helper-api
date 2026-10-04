<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** How a run of a scheduled household job went. */
enum JobStatus: string
{
    use HasValues;

    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
