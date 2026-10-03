<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\PurchaseRequest;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;

class PurchaseController extends HouseholdDataController
{
    protected string $model = Purchase::class;

    protected string $resource = PurchaseResource::class;

    protected string $request = PurchaseRequest::class;

    protected ?string $dateColumn = 'bought_on';
}
