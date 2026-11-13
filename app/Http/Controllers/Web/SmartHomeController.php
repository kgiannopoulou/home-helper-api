<?php

namespace App\Http\Controllers\Web;

use App\Models\Household;
use App\Models\SmartHomeConnection;
use App\Policies\SmartHomePolicy;
use App\SmartHome\Connections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Smart home page (resources/js/pages/smart-home.tsx): the connection to
 * the household's Home Assistant (docs/smart-home.md).
 */
class SmartHomeController extends WebController
{
    public function show(Request $request): Response
    {
        $household = $this->household($request);
        $user = $request->user();
        $policy = new SmartHomePolicy;
        $connection = $household->smartHomeConnection;

        return Inertia::render('smart-home', [
            'connection' => $connection ? $this->connection($connection) : null,
            'webhook' => $request->session()->get('smart_home_webhook'),
            'can' => [
                'manage' => $policy->manage($user, $household),
                'control' => $policy->control($user, $household),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function connection(SmartHomeConnection $c): array
    {
        return [
            'base_url' => $c->base_url,
            'status' => $c->status,
            'status_message' => $c->status_message,
            'location_name' => $c->location_name,
            'ha_version' => $c->ha_version,
            'last_ok_at' => $c->last_ok_at?->toIso8601String(),
            'last_event_at' => $c->last_event_at?->toIso8601String(),
            'last_synced_at' => $c->last_synced_at?->toIso8601String(),
            'webhook_url' => route('api.smart-home.states'),
        ];
    }

    public function connect(Request $request, Connections $connections): RedirectResponse
    {
        $household = $this->household($request);
        $this->authorizeManage($request, $household);
        $data = $request->validate([
            'url' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string', 'min:20', 'max:1000'],
        ]);
        [$connection, $secret] = $connections->connect($household, $request->user(), $data['url'], $data['token']);

        return $this->showWebhookOnce($connection, $secret);
    }

    public function check(Request $request, Connections $connections): RedirectResponse
    {
        $connection = $this->connectionOf($request);
        $connections->check($connection);

        return to_route('smart-home');
    }

    public function rotate(Request $request, Connections $connections): RedirectResponse
    {
        $household = $this->household($request);
        $this->authorizeManage($request, $household);
        $connection = $this->connectionOf($request);

        return $this->showWebhookOnce($connection, $connections->rotateSecret($connection));
    }

    /** Devices, readings and rules go with it (foreign keys cascade) */
    public function disconnect(Request $request): RedirectResponse
    {
        $household = $this->household($request);
        $this->authorizeManage($request, $household);
        $this->connectionOf($request)->delete();

        return to_route('smart-home');
    }

    private function showWebhookOnce(SmartHomeConnection $connection, string $secret): RedirectResponse
    {
        return to_route('smart-home')->with('smart_home_webhook', [
            'url' => route('api.smart-home.states'),
            'token' => SmartHomeConnection::webhookToken($connection->id, $secret),
        ]);
    }

    protected function connectionOf(Request $request): SmartHomeConnection
    {
        $connection = $this->household($request)->smartHomeConnection;
        abort_unless($connection instanceof SmartHomeConnection, 404);

        return $connection;
    }

    protected function authorizeManage(Request $request, Household $household): void
    {
        abort_unless((new SmartHomePolicy)->manage($request->user(), $household), 403);
    }
}
