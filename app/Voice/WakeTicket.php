<?php

namespace App\Voice;

use App\Models\User;

/**
 * Lets one person's browser open the wake-word socket on the voice service
 * for a few minutes, without ever seeing the service token:
 * `<user id>.<expires>.<HMAC-SHA256 of "wake:<user id>:<expires>">`.
 * services/voice/app/auth.py checks it the same way.
 */
class WakeTicket
{
    public const MINUTES = 15;

    public static function issue(User $user, ?int $expires = null): ?string
    {
        $token = config('homehelper.voice.token');
        if (! $token) {
            return null;
        }
        $expires ??= now()->addMinutes(self::MINUTES)->getTimestamp();

        return $user->id.'.'.$expires.'.'.hash_hmac('sha256', "wake:{$user->id}:{$expires}", (string) $token);
    }
}
