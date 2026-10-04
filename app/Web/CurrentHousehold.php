<?php

namespace App\Web;

use App\Models\Household;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The household the browser dashboard shows. The phone sends the household in every
 * URL; the dashboard remembers your choice in the session instead, and falls back to
 * your first household, so the same login shows the same household as on the phone.
 */
class CurrentHousehold
{
    public const SESSION_KEY = 'household_id';

    public static function get(Request $request): ?Household
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return null;
        }
        $households = $user->households()->orderBy('households.created_at')->orderBy('households.id');
        $chosen = $request->session()->get(self::SESSION_KEY);

        return ($chosen ? (clone $households)->whereKey($chosen)->first() : null) ?? $households->first();
    }

    public static function choose(Request $request, Household $household): void
    {
        $request->session()->put(self::SESSION_KEY, $household->id);
    }
}
