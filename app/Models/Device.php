<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone that gets push notifications through Expo.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $personal_access_token_id
 * @property string $expo_token
 * @property string|null $platform
 * @property string|null $name
 */
#[Fillable(['expo_token', 'platform', 'name'])]
class Device extends Model
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
