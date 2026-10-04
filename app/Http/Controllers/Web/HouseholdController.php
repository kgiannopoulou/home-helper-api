<?php

namespace App\Http\Controllers\Web;

use App\Actions\Households\InviteMember;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Invite;
use App\Models\User;
use App\Web\CurrentHousehold;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Members and invites, switching household, and starting a new one.
 */
class HouseholdController extends WebController
{
    public function show(Request $request): Response
    {
        $household = CurrentHousehold::get($request);

        return Inertia::render('household', [
            'members' => $household ? $household->users()->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->membership->role,
                'joined' => $u->membership->created_at?->toDateString(),
            ])->all() : [],
            'invites' => $household ? $household->invites()->whereNull('accepted_at')->where('expires_at', '>', now())->latest()->get()
                ->map(fn (Invite $i) => ['id' => $i->id, 'email' => $i->email, 'role' => $i->role, 'expires_at' => $i->expires_at->toDateString()])->all() : [],
            'canInvite' => $household && $request->user()->can('invite', $household),
        ]);
    }

    public function invite(Request $request, InviteMember $invite): RedirectResponse
    {
        $household = CurrentHousehold::get($request) ?? abort(404);
        Gate::authorize('invite', $household);

        $sent = $invite($household, $request->user(), $request->only('email', 'role'));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Invite sent to {$sent->email}."]);

        return back();
    }

    public function switch(Request $request, Household $household): RedirectResponse
    {
        Gate::authorize('view', $household);
        CurrentHousehold::choose($request, $household);

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
        ]);

        $household = DB::transaction(function () use ($data, $request) {
            $household = Household::create(['name' => $data['name'], 'currency' => strtoupper($data['currency'])]);
            $household->addMember($request->user(), HouseholdRole::Owner);

            return $household;
        });
        CurrentHousehold::choose($request, $household);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$household->name} is ready. Invite the people you live with."]);

        return redirect()->route('household.show');
    }
}
