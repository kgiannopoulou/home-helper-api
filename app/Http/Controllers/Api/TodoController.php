<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\TodoRequest;
use App\Http\Resources\TodoResource;
use App\Models\Todo;

class TodoController extends HouseholdDataController
{
    protected string $model = Todo::class;

    protected string $resource = TodoResource::class;

    protected string $request = TodoRequest::class;

    protected ?string $dateColumn = 'due_on';

    protected ?string $creatorColumn = 'user_id';
}
