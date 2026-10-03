<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ItemPriceRequest;
use App\Http\Resources\ItemPriceResource;
use App\Models\ItemPrice;

class ItemPriceController extends HouseholdDataController
{
    protected string $model = ItemPrice::class;

    protected string $resource = ItemPriceResource::class;

    protected string $request = ItemPriceRequest::class;

    protected ?string $dateColumn = 'seen_on';
}
