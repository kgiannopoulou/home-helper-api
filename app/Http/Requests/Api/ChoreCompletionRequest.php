<?php

namespace App\Http\Requests\Api;

class ChoreCompletionRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'chore_id' => ['required', $this->inHousehold('chores')],
            'user_id' => ['sometimes', $this->member()],
            'done_at' => ['required', 'date'],
            'minutes' => ['required', 'integer', 'between:1,600'],
        ];
    }
}
