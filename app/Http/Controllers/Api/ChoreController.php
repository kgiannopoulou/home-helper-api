<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ChoreRequest;
use App\Http\Resources\ChoreResource;
use App\Models\Chore;

class ChoreController extends HouseholdDataController
{
    protected string $model = Chore::class;

    protected string $resource = ChoreResource::class;

    protected string $request = ChoreRequest::class;

    protected string $orderBy = 'name';
}
