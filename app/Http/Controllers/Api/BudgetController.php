<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\BudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;

class BudgetController extends HouseholdDataController
{
    protected string $model = Budget::class;

    protected string $resource = BudgetResource::class;

    protected string $request = BudgetRequest::class;

    protected string $orderBy = 'period';
}
