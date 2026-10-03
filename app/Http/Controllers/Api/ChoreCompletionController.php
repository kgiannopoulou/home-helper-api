<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ChoreCompletionRequest;
use App\Http\Resources\ChoreCompletionResource;
use App\Models\ChoreCompletion;

class ChoreCompletionController extends HouseholdDataController
{
    protected string $model = ChoreCompletion::class;

    protected string $resource = ChoreCompletionResource::class;

    protected string $request = ChoreCompletionRequest::class;

    protected ?string $dateColumn = 'done_at';

    protected ?string $creatorColumn = 'user_id';
}
