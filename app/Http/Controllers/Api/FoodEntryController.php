<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\FoodEntryRequest;
use App\Http\Resources\FoodEntryResource;
use App\Models\FoodEntry;

class FoodEntryController extends HouseholdDataController
{
    protected string $model = FoodEntry::class;

    protected string $resource = FoodEntryResource::class;

    protected string $request = FoodEntryRequest::class;

    protected ?string $dateColumn = 'date';

    protected bool $personal = true;
}
