<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ShoppingTripRequest;
use App\Http\Resources\ShoppingTripResource;
use App\Models\ShoppingTrip;

class ShoppingTripController extends HouseholdDataController
{
    protected string $model = ShoppingTrip::class;

    protected string $resource = ShoppingTripResource::class;

    protected string $request = ShoppingTripRequest::class;

    protected ?string $dateColumn = 'date';

    protected ?string $creatorColumn = 'user_id';
}
