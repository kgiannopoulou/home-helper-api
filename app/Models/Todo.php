<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\TodoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A to-do, optionally with a due day and how long it takes.
 */
#[Fillable(['user_id', 'title', 'due_on', 'minutes', 'done_at'])]
class Todo extends Model
{
    /** @use HasFactory<TodoFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'minutes' => 'integer',
            'done_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
