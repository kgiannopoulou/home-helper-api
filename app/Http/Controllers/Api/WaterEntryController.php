<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\WaterEntryRequest;
use App\Http\Resources\WaterEntryResource;
use App\Models\WaterEntry;

class WaterEntryController extends HouseholdDataController
{
    protected string $model = WaterEntry::class;

    protected string $resource = WaterEntryResource::class;

    protected string $request = WaterEntryRequest::class;

    protected ?string $dateColumn = 'date';

    protected bool $personal = true;
}
