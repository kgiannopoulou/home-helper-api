<?php

namespace App\Http\Controllers\Web;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Household;
use App\Models\SmartDevice;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEvent;
use App\Policies\SmartHomePolicy;
use App\SmartHome\Automation\Describe;
use App\SmartHome\Automation\Templates;
use App\SmartHome\Comfort;
use App\SmartHome\Connections;
use App\SmartHome\Control;
use App\SmartHome\DevicePresenter;
use App\SmartHome\Discovery;
use App\SmartHome\HomeAssistantError;
use App\SmartHome\HomeSummary;
use App\SmartHome\Import;
use App\SmartHome\Notifications;
use App\SmartHome\Openings;
use App\SmartHome\Readings;
use App\SmartHome\Sensitivity;
use App\Models\SmartHomeAction;
use App\Policies\SmartHomePolicy as Policy;
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
    public function show(Request $request, Import $import, Readings $readings, Openings $openings): Response
    {
        $household = $this->household($request);
        $user = $request->user();
        $policy = new SmartHomePolicy;
        $connection = $household->smartHomeConnection;
        $devices = $household->smartDevices()->active()->with('room')->orderBy('name')->get();
        $sensors = $devices->filter(fn (SmartDevice $d) => in_array($d->kind, Readings::KINDS, true))->values();

        return Inertia::render('smart-home', [
            'connection' => $connection ? $this->connection($connection) : null,
            'webhook' => $request->session()->get('smart_home_webhook'),
            'summary' => HomeSummary::for($household),
            'devices' => $devices->map(fn (SmartDevice $d) => DevicePresenter::toArray($d))->values()->all(),
            'comfort' => Comfort::rooms($devices),
            'openings' => $openings->overview($devices, $this->today()->startOfDay()),
            'events' => SmartHomeEvent::query()->where('household_id', $household->id)->with('device:id,name,kind')
                ->orderByDesc('occurred_at')->limit(30)->get()
                ->map(fn (SmartHomeEvent $e) => [
                    'id' => $e->id,
                    'type' => $e->type,
                    'severity' => $e->severity,
                    'message' => $e->message,
                    'at' => $e->occurred_at->toIso8601String(),
                ])->all(),
            'climate' => [
                'sensors' => $sensors->map(fn (SmartDevice $d) => ['id' => $d->id, 'name' => $d->name, 'kind' => $d->kind, 'room' => $d->room !== null ? $d->room->name : $d->area])->all(),
                'history' => $readings->history($sensors),
            ],
            'candidates' => $policy->manage($user, $household)
                ? $import->candidates($household)->load('room')->map(fn (SmartDevice $d) => DevicePresenter::toArray($d))->values()->all()
                : [],
            'rooms' => $household->rooms()->where('personal', false)->orderBy('name')->get(['id', 'name'])->toArray(),
            'automations' => $this->automations($household),
            'approvals' => SmartHomeAction::query()->where('household_id', $household->id)
                ->where(fn ($q) => $q->where('status', 'awaiting_approval')->orWhere(fn ($q) => $q->whereNotNull('decided_at')->where('decided_at', '>=', now()->subDay())))
                ->with(['device', 'requester:id,name', 'decider:id,name'])->latest()->limit(20)->get()
                ->map(fn (SmartHomeAction $a) => [
                    'id' => $a->id,
                    'label' => $a->label(),
                    'status' => $a->status,
                    'source' => $a->source,
                    'requested_by' => $a->requester?->name,
                    'decided_by' => $a->decider?->name,
                    'error' => $a->error,
                    'expires_at' => $a->expires_at?->toIso8601String(),
                    'created_at' => $a->created_at->toIso8601String(),
                ])->all(),
            'notifications' => [
                'settings' => Notifications::settings($connection),
                'labels' => array_map(fn (array $t) => $t[1], Notifications::TYPES),
                'night' => config('homehelper.smart_home.night'),
            ],
            'can' => [
                'manage' => $policy->manage($user, $household),
                'control' => $policy->control($user, $household),
                'approve' => $policy->approve($user, $household),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function automations(Household $household): array
    {
        $rules = $household->automationRules()->orderBy('name')->get();
        $devices = $household->smartDevices()->get()->keyBy('id')->all();
        $lastRuns = AutomationRun::query()->where('household_id', $household->id)->latest('ran_at')->limit(30)->get();

        return [
            'rules' => $rules->map(fn (AutomationRule $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'enabled' => $r->enabled,
                'template' => $r->template,
                'description' => Describe::rule($r, $devices),
                'trigger' => $r->trigger,
                'conditions' => $r->conditions,
                'actions' => $r->actions,
                'cooldown_minutes' => $r->cooldown_minutes,
                'last_fired_at' => $r->last_fired_at?->toIso8601String(),
            ])->values()->all(),
            'templates' => Templates::suggestions($household),
            'runs' => $lastRuns->map(fn (AutomationRun $run) => [
                'id' => $run->id,
                'rule' => $rules->firstWhere('id', $run->rule_id)?->name,
                'trigger' => $run->trigger,
                'status' => $run->status,
                'reason' => $run->reason,
                'results' => $run->results ?? [],
                'at' => $run->ran_at->toIso8601String(),
            ])->all(),
        ];
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
            'webhook_url' => route('api.home-assistant.states'),
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

    /** Which events reach the phones: type => always | night | never */
    public function notifications(Request $request): RedirectResponse
    {
        $household = $this->household($request);
        $this->authorizeManage($request, $household);
        $connection = $this->connectionOf($request);
        $data = $request->validate([
            'notify' => ['required', 'array'],
            'notify.*' => ['required', 'in:'.implode(',', Notifications::LEVELS)],
        ]);
        $known = array_intersect_key($data['notify'], Notifications::TYPES);
        $connection->forceFill(['notify' => array_merge(Notifications::settings($connection), $known)])->save();

        return to_route('smart-home');
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
            'sensitive' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['sensitive']) && ! Sensitivity::canChange($device)) {
            unset($data['sensitive']);
        }
        $device->forceFill($data)->save();

        return to_route('smart-home');
    }

    /** A button on a device tile: on, off, brightness */
    public function control(Request $request, SmartDevice $device, Control $control): RedirectResponse
    {
        $this->inHousehold($request, $device);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:turn_on,turn_off,toggle,set_brightness,lock,unlock'],
            'brightness' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $action = $control->request($device, $request->user(), $data['action'], isset($data['brightness']) ? ['brightness' => (int) $data['brightness']] : []);

        return $action->status === 'failed'
            ? to_route('smart-home')->withErrors(['control' => $action->error])
            : to_route('smart-home');
    }

    public function approve(Request $request, SmartHomeAction $action, Control $control): RedirectResponse
    {
        abort_unless($action->household_id === $this->household($request)->id, 404);
        $action = $control->approve($action, $request->user());

        return $action->status === 'failed'
            ? to_route('smart-home')->withErrors(['control' => $action->error])
            : to_route('smart-home');
    }

    public function decline(Request $request, SmartHomeAction $action, Control $control): RedirectResponse
    {
        abort_unless($action->household_id === $this->household($request)->id, 404);
        $control->decline($action, $request->user());

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
            'url' => route('api.home-assistant.states'),
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
