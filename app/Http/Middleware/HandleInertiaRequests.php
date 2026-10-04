<?php

namespace App\Http\Middleware;

use App\Models\Household;
use App\Web\CurrentHousehold;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // The household the dashboard shows, and the others you can switch to
            'household' => fn () => $this->household($request),
            'households' => fn () => $request->user()?->households()->orderBy('name')->get(['households.id', 'households.name'])
                ->map(fn (Household $h) => ['id' => $h->id, 'name' => $h->name])->all() ?? [],
        ];
    }

    /**
     * @return array{id: string, name: string, currency: string, role: string}|null
     */
    private function household(Request $request): ?array
    {
        $h = CurrentHousehold::get($request);

        return $h ? ['id' => $h->id, 'name' => $h->name, 'currency' => $h->currency, 'role' => $request->user()->roleIn($h)?->value ?? 'member'] : null;
    }
}
