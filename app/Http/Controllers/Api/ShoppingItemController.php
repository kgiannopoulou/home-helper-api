<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ShoppingItemRequest;
use App\Http\Resources\ShoppingItemResource;
use App\Models\ShoppingItem;

class ShoppingItemController extends HouseholdDataController
{
    protected string $model = ShoppingItem::class;

    protected string $resource = ShoppingItemResource::class;

    protected string $request = ShoppingItemRequest::class;

    protected ?string $creatorColumn = 'added_by';
}
