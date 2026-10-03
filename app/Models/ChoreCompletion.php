<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\ChoreCompletionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One time a chore was done, and by whom.
 */
#[Fillable(['chore_id', 'user_id', 'done_at', 'minutes'])]
class ChoreCompletion extends Model
{
    /** @use HasFactory<ChoreCompletionFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'done_at' => 'datetime',
            'minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Chore, $this>
     */
    public function chore(): BelongsTo
    {
        return $this->belongsTo(Chore::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
