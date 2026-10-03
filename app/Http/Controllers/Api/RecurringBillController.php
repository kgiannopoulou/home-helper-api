<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\RecurringBillRequest;
use App\Http\Resources\RecurringBillResource;
use App\Models\RecurringBill;

class RecurringBillController extends HouseholdDataController
{
    protected string $model = RecurringBill::class;

    protected string $resource = RecurringBillResource::class;

    protected string $request = RecurringBillRequest::class;

    protected string $orderBy = 'name';
}
