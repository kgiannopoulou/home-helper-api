<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\AdminItemRequest;
use App\Http\Resources\AdminItemResource;
use App\Models\AdminItem;

class AdminItemController extends HouseholdDataController
{
    protected string $model = AdminItem::class;

    protected string $resource = AdminItemResource::class;

    protected string $request = AdminItemRequest::class;

    protected ?string $dateColumn = 'due_on';
}
