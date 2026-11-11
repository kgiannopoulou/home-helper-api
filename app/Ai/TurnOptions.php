<?php

namespace App\Ai;

use App\Voice\ResponseStyle;
use Closure;

/**
 * How one turn is asked (docs/voice.md#the-voice-turn): spoken or typed, in
 * which style, and whether the person has cancelled it while it runs.
 */
final readonly class TurnOptions
{
    /**
     * @param  (Closure(): bool)|null  $cancelled  asked between model rounds; true stops the turn
     */
    public function __construct(
        public ?ResponseStyle $spoken = null,
        public ?Closure $cancelled = null,
    ) {}

    /** @phpstan-impure the person may cancel between two calls */
    public function isCancelled(): bool
    {
        return $this->cancelled !== null && ($this->cancelled)();
    }
}
