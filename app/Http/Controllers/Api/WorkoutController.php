<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\WorkoutRequest;
use App\Http\Resources\WorkoutResource;
use App\Models\Workout;

class WorkoutController extends HouseholdDataController
{
    protected string $model = Workout::class;

    protected string $resource = WorkoutResource::class;

    protected string $request = WorkoutRequest::class;

    protected ?string $dateColumn = 'date';

    protected bool $personal = true;
}
