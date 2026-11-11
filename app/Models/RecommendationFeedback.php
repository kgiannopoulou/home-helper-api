<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A member said a recommendation was useful, or not now (docs/briefings.md#recommendations).
 * Dismissed ones stay hidden for a while; a rule someone keeps dismissing ranks lower for them.
 *
 * @property int $id
 * @property string $household_id
 * @property int $user_id
 * @property string $key
 * @property string $rule
 * @property string $verdict useful | dismissed
 * @property CarbonImmutable|null $hidden_until
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['user_id', 'key', 'rule', 'verdict', 'hidden_until'])]
class RecommendationFeedback extends Model
{
    use BelongsToHousehold;

    protected $table = 'recommendation_feedback';

    protected function casts(): array
    {
        return ['hidden_until' => 'date'];
    }

    /** "useful" or "dismissed" (hidden for homehelper.briefing.dismiss_days), replacing an earlier verdict */
    public static function record(Household $household, User $user, string $key, string $verdict, CarbonImmutable $today): self
    {
        $feedback = self::query()->firstOrNew(['household_id' => $household->id, 'user_id' => $user->id, 'key' => $key]);
        $feedback->forceFill([
            'household_id' => $household->id,
            'rule' => strstr($key, ':', true) ?: $key,
            'verdict' => $verdict,
            'hidden_until' => $verdict === 'dismissed' ? $today->addDays((int) config('homehelper.briefing.dismiss_days'))->toDateString() : null,
        ])->save();

        return $feedback;
    }
}
