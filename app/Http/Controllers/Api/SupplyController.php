<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\SupplyRequest;
use App\Http\Resources\SupplyResource;
use App\Models\Supply;

class SupplyController extends HouseholdDataController
{
    protected string $model = Supply::class;

    protected string $resource = SupplyResource::class;

    protected string $request = SupplyRequest::class;

    protected string $orderBy = 'name';
}
