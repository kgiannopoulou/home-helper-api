<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\EventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;

class EventController extends HouseholdDataController
{
    protected string $model = Event::class;

    protected string $resource = EventResource::class;

    protected string $request = EventRequest::class;

    protected ?string $dateColumn = 'starts_at';

    protected ?string $creatorColumn = 'user_id';
}
