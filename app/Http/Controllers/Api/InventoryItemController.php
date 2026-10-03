<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\InventoryItemRequest;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;

class InventoryItemController extends HouseholdDataController
{
    protected string $model = InventoryItem::class;

    protected string $resource = InventoryItemResource::class;

    protected string $request = InventoryItemRequest::class;

    protected ?string $dateColumn = 'expires_on';
}
