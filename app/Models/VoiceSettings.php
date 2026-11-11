<?php

namespace App\Models;

use App\Voice\ResponseStyle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's voice choices (docs/voice.md#settings), across their households.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $voice
 * @property ResponseStyle $style
 * @property float $rate
 * @property string|null $language
 * @property bool $wake_word
 * @property bool $continuous
 * @property int|null $keep_days
 * @property bool $remember
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['voice', 'style', 'rate', 'language', 'wake_word', 'continuous', 'keep_days', 'remember'])]
class VoiceSettings extends Model
{
    protected $attributes = [
        'style' => 'conversational',
        'rate' => 1.0,
        'wake_word' => false,
        'continuous' => true,
        'remember' => true,
    ];

    protected function casts(): array
    {
        return [
            'style' => ResponseStyle::class,
            'rate' => 'float',
            'wake_word' => 'boolean',
            'continuous' => 'boolean',
            'keep_days' => 'integer',
            'remember' => 'boolean',
        ];
    }

    /** Their settings, or the defaults (not saved until they change something) */
    public static function for(User $user): self
    {
        $settings = self::query()->firstOrNew(['user_id' => $user->id]);
        $settings->user_id = $user->id; // not fillable: never from a request

        return $settings;
    }

    /** The voice to speak with: theirs if it is still offered, else the first one */
    public function voiceOrDefault(): string
    {
        $voices = (array) config('homehelper.voice.voices');

        return in_array($this->voice, $voices, true) ? (string) $this->voice : (string) $voices[0];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    public function toPublic(): array
    {
        return [
            'voice' => $this->voiceOrDefault(),
            'style' => $this->style->value,
            'rate' => $this->rate,
            'language' => $this->language,
            'wake_word' => $this->wake_word,
            'continuous' => $this->continuous,
            'keep_days' => $this->keep_days,
            'remember' => $this->remember,
        ];
    }
}
