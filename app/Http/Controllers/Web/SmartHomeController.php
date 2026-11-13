<?php

namespace App\Http\Controllers\Web;

use App\Models\Household;
use App\Models\SmartDevice;
use App\Models\SmartHomeConnection;
use App\Policies\SmartHomePolicy;
use App\SmartHome\Connections;
use App\SmartHome\DevicePresenter;
use App\SmartHome\Discovery;
use App\SmartHome\HomeAssistantError;
use App\SmartHome\Import;
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
    public function show(Request $request, Import $import): Response
    {
        $household = $this->household($request);
        $user = $request->user();
        $policy = new SmartHomePolicy;
        $connection = $household->smartHomeConnection;

        return Inertia::render('smart-home', [
            'connection' => $connection ? $this->connection($connection) : null,
            'webhook' => $request->session()->get('smart_home_webhook'),
            'devices' => $household->smartDevices()->active()->with('room')->orderBy('name')->get()
                ->map(fn (SmartDevice $d) => DevicePresenter::toArray($d))->values()->all(),
            'candidates' => $policy->manage($user, $household)
                ? $import->candidates($household)->load('room')->map(fn (SmartDevice $d) => DevicePresenter::toArray($d))->values()->all()
                : [],
            'rooms' => $household->rooms()->where('personal', false)->orderBy('name')->get(['id', 'name'])->toArray(),
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

    public function connect(Request $request, Connections $connections, Discovery $discovery): RedirectResponse
    {
        $household = $this->household($request);
        $this->authorizeManage($request, $household);
        $data = $request->validate([
            'url' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string', 'min:20', 'max:1000'],
        ]);
        [$connection, $secret] = $connections->connect($household, $request->user(), $data['url'], $data['token']);
        // Straight away, so the owner can choose devices on the next page
        try {
            $discovery->sync($connection);
        } catch (HomeAssistantError) {
            // Shown on the connection's status; the scheduled sync tries again
        }

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

    /** "Sync now": find new devices and refresh every state */
    public function sync(Request $request, Discovery $discovery): RedirectResponse
    {
        try {
            $discovery->sync($this->connectionOf($request));
        } catch (HomeAssistantError $e) {
            return to_route('smart-home')->withErrors(['sync' => $e->advice()]);
        }

        return to_route('smart-home');
    }

    public function import(Request $request, Import $import): RedirectResponse
    {
        $data = $request->validate(['devices' => ['required', 'array', 'min:1', 'max:200'], 'devices.*' => ['string', 'size:26']]);
        $import->import($this->household($request), $request->user(), array_values($data['devices']));

        return to_route('smart-home');
    }

    public function updateDevice(Request $request, SmartDevice $device): RedirectResponse
    {
        $household = $this->household($request);
        $this->inHousehold($request, $device);
        $this->authorizeManage($request, $household);
        $data = $request->validate([
            'room_id' => ['sometimes', 'nullable', 'string', 'exists:rooms,id,household_id,'.$household->id],
        ]);
        $device->forceFill($data)->save();

        return to_route('smart-home');
    }

    /** Out of Home Helper, not out of Home Assistant */
    public function removeDevice(Request $request, SmartDevice $device, Import $import): RedirectResponse
    {
        $this->inHousehold($request, $device);
        $import->remove($device, $request->user());

        return to_route('smart-home');
    }

    /** Another household's device is a 404, not a 403 */
    protected function inHousehold(Request $request, SmartDevice $device): void
    {
        abort_unless($device->household_id === $this->household($request)->id, 404);
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
