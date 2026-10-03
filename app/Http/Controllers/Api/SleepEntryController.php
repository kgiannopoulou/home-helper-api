<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\SleepEntryRequest;
use App\Http\Resources\SleepEntryResource;
use App\Models\SleepEntry;

class SleepEntryController extends HouseholdDataController
{
    protected string $model = SleepEntry::class;

    protected string $resource = SleepEntryResource::class;

    protected string $request = SleepEntryRequest::class;

    protected ?string $dateColumn = 'date';

    protected bool $personal = true;
}
