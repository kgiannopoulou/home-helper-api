<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\WeightRequest;
use App\Http\Resources\WeightResource;
use App\Models\Weight;

class WeightController extends HouseholdDataController
{
    protected string $model = Weight::class;

    protected string $resource = WeightResource::class;

    protected string $request = WeightRequest::class;

    protected ?string $dateColumn = 'date';

    protected bool $personal = true;
}
