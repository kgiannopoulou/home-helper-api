<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\PlantRequest;
use App\Http\Resources\PlantResource;
use App\Models\Plant;

class PlantController extends HouseholdDataController
{
    protected string $model = Plant::class;

    protected string $resource = PlantResource::class;

    protected string $request = PlantRequest::class;

    protected string $orderBy = 'name';

    protected array $extraSortable = ['next_watering_at'];
}
