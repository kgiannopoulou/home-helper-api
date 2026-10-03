<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\RoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;

class RoomController extends HouseholdDataController
{
    protected string $model = Room::class;

    protected string $resource = RoomResource::class;

    protected string $request = RoomRequest::class;

    protected string $orderBy = 'name';
}
