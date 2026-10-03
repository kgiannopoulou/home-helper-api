<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;

class ExpenseController extends HouseholdDataController
{
    protected string $model = Expense::class;

    protected string $resource = ExpenseResource::class;

    protected string $request = ExpenseRequest::class;

    protected ?string $dateColumn = 'date';

    protected ?string $creatorColumn = 'user_id';
}
