<?php

namespace App\Console\Commands;

use App\Enums\HouseholdRole;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Household;
use App\Models\Room;
use App\Models\SmartDevice;
use App\Models\SmartDeviceReading;
use App\Models\SmartHomeAction;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEvent;
use App\Models\User;
use App\SmartHome\Automation\RuleValidator;
use App\SmartHome\Automation\Templates;
use App\SmartHome\Connections;
use App\SmartHome\Control;
use App\SmartHome\Discovery;
use App\SmartHome\HomeAssistant;
use App\SmartHome\HomeAssistantError;
use App\SmartHome\Import;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * php artisan smart-home:e2e <stage> — Home Helper against a real Home
 * Assistant (day 84, docs/smart-home/testing.md). The Pest tests use a fake
 * Home Assistant; this uses the real one: the test home in Docker, or a Pi
 * at home. ops/home-assistant/scripts/e2e.sh runs the stages in order and
 * stops and starts Home Assistant between them.
 *
 *   setup      a throwaway household (owner + member, rooms), connected and imported;
 *              prints the webhook token for Home Assistant's secrets.yaml
 *   devices    lights, dimming, plugs, the physical world through the webhook,
 *              sensors, an automation, the approval flow, an unplugged lamp,
 *              wrong tokens and replayed webhooks
 *   offline    Home Assistant is stopped: everything fails fast and says why
 *   recovered  Home Assistant is back: the connection heals, the restart's
 *              burst of "unavailable" doesn't pretend doors opened
 *   cleanup    deletes the household
 *
 * The household is found again by name between stages (E2E_HOUSEHOLD). The
 * scheduler must NOT run during the test: every state change asserted in
 * `devices` must arrive through the webhook, not a sync.
 */
class SmartHomeE2e extends Command
{
    protected $signature = 'smart-home:e2e
        {stage : setup|devices|offline|recovered|cleanup}
        {--url=http://homeassistant:8123 : Home Assistant, as the app reaches it}
        {--token= : a long-lived access token (or HOME_ASSISTANT_TOKEN)}
        {--app=http://hh-web:8077 : the running app, for the webhook checks}
        {--name=E2E smart home : the throwaway household}
        {--email=demo@homehelper.test : its owner}
        {--wait=15 : seconds to wait for Home Assistant to push a change}
        {--webhook= : the webhook token setup printed, for the replay check}';

    protected $description = 'End-to-end checks against a real Home Assistant';

    private int $passed = 0;

    public function handle(): int
    {
        $stage = (string) $this->argument('stage');
        $steps = match ($stage) {
            'setup' => fn () => $this->setUp(),
            'devices' => fn () => $this->devices(),
            'offline' => fn () => $this->offline(),
            'recovered' => fn () => $this->recovered(),
            'cleanup' => fn () => $this->cleanUp(),
            default => null,
        };
        if (! $steps) {
            $this->error("Unknown stage {$stage}");

            return self::INVALID;
        }

        try {
            $steps();
        } catch (Throwable $e) {
            $this->err('   FAILED '.$e->getMessage());

            return self::FAILURE;
        }
        $this->err("   {$this->passed} checks passed ({$stage})");

        return self::SUCCESS;
    }

    // ---- setup ----------------------------------------------------------

    private function setUp(): void
    {
        $owner = User::query()->where('email', $this->option('email'))->firstOrFail();
        $this->cleanUp(quiet: true);

        $this->step('A household of two: the owner and a member, with the test home\'s rooms');
        $home = Household::query()->forceCreate(['name' => $this->option('name')]);
        $home->addMember($owner, HouseholdRole::Owner);
        $member = User::query()->firstOrCreate(['email' => 'e2e-member@homehelper.test'], ['name' => 'E2E member', 'password' => Hash::make(Str::random(32))]);
        $home->addMember($member);
        foreach (['Living room', 'Kitchen', 'Bathroom', 'Bedroom', 'Hallway'] as $room) {
            $r = new Room(['name' => $room]);
            $r->household_id = $home->id;
            $r->save();
        }
        $this->ok("{$home->name} ({$home->id})");

        $this->step('Connect: the token is checked against Home Assistant itself');
        [$connection, $secret] = app(Connections::class)->connect($home, $owner, (string) $this->option('url'), $this->token());
        $this->ok("{$connection->location_name}, Home Assistant {$connection->ha_version}");

        $this->step('Discover and import every device');
        $r = app(Discovery::class)->sync($connection);
        $this->check($r['found'] >= 20, "found {$r['found']} devices");
        $ids = array_values(app(Import::class)->candidates($home)->pluck('id')->map(fn ($id) => (string) $id)->all());
        $imported = app(Import::class)->import($home, $owner, $ids);
        $kinds = SmartDevice::query()->where('household_id', $home->id)->where('imported', true)->pluck('kind')->countBy()->sortKeys()->all();
        $this->ok("imported {$imported}: ".collect($kinds)->map(fn ($n, $k) => "{$n} {$k}")->implode(', '));
        foreach (['light.living_room_lamp' => 'Living room', 'sensor.bathroom_humidity' => 'Bathroom', 'lock.front_door_lock' => 'Hallway'] as $id => $room) {
            $this->check($this->device($id)->room?->name === $room, "{$id} is in the {$room} (from the Home Assistant area)");
        }

        // stdout: the one line the script needs
        $this->line(SmartHomeConnection::webhookToken($connection->id, $secret));
    }

    // ---- devices --------------------------------------------------------

    private function devices(): void
    {
        [$home, $owner, $member] = $this->household();
        $ha = $this->ha();
        $control = app(Control::class);
        // Start from a known house
        foreach (['sim_living_room_lamp', 'sim_dehumidifier', 'sim_coffee_machine', 'sim_front_door_open', 'sim_living_room_lamp_offline'] as $sim) {
            $ha->callService('input_boolean', 'turn_off', ['entity_id' => "input_boolean.{$sim}"]);
        }
        $ha->callService('input_boolean', 'turn_on', ['entity_id' => 'input_boolean.sim_front_door_locked']);
        $ha->callService('input_number', 'set_value', ['entity_id' => 'input_number.sim_bathroom_humidity', 'value' => 55]);
        $this->until('the house is back to its start', fn () => $this->device('light.living_room_lamp')->state === 'off' && $this->device('lock.front_door_lock')->state === 'locked'
            && $this->device('binary_sensor.front_door')->state === 'off' && $this->device('switch.dehumidifier_plug')->state === 'off'
            && $this->device('sensor.bathroom_humidity')->numericState() === 55.0);

        $this->step('Light: on from the dashboard, Home Assistant switches it, the reply updates Home Helper');
        $row = $control->request($this->device('light.living_room_lamp'), $owner, 'turn_on');
        $this->check($row->status === 'done', 'action done');
        $this->check($ha->state('light.living_room_lamp')['state'] === 'on', 'Home Assistant says on');
        $this->check($this->device('light.living_room_lamp')->state === 'on', 'Home Helper says on');

        $this->step('Dimming in percent becomes Home Assistant\'s 0-255');
        $control->request($this->device('light.living_room_lamp'), $member, 'set_brightness', ['brightness' => 40]);
        $brightness = (int) ($ha->state('light.living_room_lamp')['attributes']['brightness'] ?? 0);
        $this->check(abs($brightness - 102) <= 1, "brightness {$brightness}/255");
        $this->until('Home Helper shows 40 %', fn () => $this->device('light.living_room_lamp')->brightnessPercent() === 40);
        $control->request($this->device('light.living_room_lamp'), $member, 'turn_off');

        $this->step('Plug: a member switches the dehumidifier on and off');
        $this->check($control->request($this->device('switch.dehumidifier_plug'), $member, 'turn_on')->status === 'done', 'on');
        $this->check($control->request($this->device('switch.dehumidifier_plug'), $member, 'turn_off')->status === 'done', 'off');

        $this->step('The physical world: a door opens in Home Assistant, the webhook tells Home Helper');
        $since = now();
        $ha->callService('input_boolean', 'turn_on', ['entity_id' => 'input_boolean.sim_front_door_open']);
        $seconds = $this->until('front door open in Home Helper', fn () => $this->device('binary_sensor.front_door')->state === 'on');
        $this->ok(sprintf('arrived in %.1f s', $seconds));
        $this->until('a "door opened" event', fn () => SmartHomeEvent::query()->where('household_id', $home->id)->where('occurred_at', '>=', $since)->whereIn('type', ['door.opened', 'window.opened'])->exists());
        $ha->callService('input_boolean', 'turn_off', ['entity_id' => 'input_boolean.sim_front_door_open']);
        $this->until('closed again', fn () => $this->device('binary_sensor.front_door')->state === 'off');

        $this->step('Sensors: the bathroom warms up, a reading is stored');
        $ha->callService('input_number', 'set_value', ['entity_id' => 'input_number.sim_bathroom_temperature', 'value' => 24.5]);
        $this->until('24.5 °C in Home Helper', fn () => $this->device('sensor.bathroom_temperature')->numericState() === 24.5);
        // One reading per sensor per minute, the latest value (App\SmartHome\Readings)
        $last = SmartDeviceReading::query()->where('device_id', $this->device('sensor.bathroom_temperature')->id)->latest('recorded_at')->first();
        $this->check((float) $last?->value === 24.5, 'the latest reading is 24.5');
        $ha->callService('input_number', 'set_value', ['entity_id' => 'input_number.sim_bathroom_temperature', 'value' => 23.0]);

        $this->step('Automation: a shower (humidity 78 %) switches the dehumidifier on, 55 % off again');
        $this->addTemplate($home, $owner, 'dehumidifier');
        $ha->callService('input_number', 'set_value', ['entity_id' => 'input_number.sim_bathroom_humidity', 'value' => 78]);
        $this->until('dehumidifier on in Home Assistant', fn () => $ha->state('switch.dehumidifier_plug')['state'] === 'on');
        $ha->callService('input_number', 'set_value', ['entity_id' => 'input_number.sim_bathroom_humidity', 'value' => 55]);
        $this->until('and off', fn () => $ha->state('switch.dehumidifier_plug')['state'] === 'off');
        // The run is written when the webhook request that fired it finishes, just after Home Assistant switched
        $runs = fn () => AutomationRun::query()->where('household_id', $home->id)->pluck('status')->countBy()->all();
        $this->until('two runs, both done', fn () => $runs() === ['done' => 2]);

        $this->step('Sensitive: a member\'s unlock waits for the owner; the owner approves; locking up never waits');
        $row = $control->request($this->device('lock.front_door_lock'), $member, 'unlock');
        $this->check($row->status === 'awaiting_approval', 'waiting for the owner');
        $this->check($ha->state('lock.front_door_lock')['state'] === 'locked', 'still locked in Home Assistant');
        $row = $control->approve($row, $owner);
        $this->check($row->status === 'done' && $ha->state('lock.front_door_lock')['state'] === 'unlocked', 'approved: unlocked');
        $this->check($control->request($this->device('lock.front_door_lock'), $member, 'lock')->status === 'done', 'member locks up at once');
        $this->check($ha->state('lock.front_door_lock')['state'] === 'locked', 'locked');

        $this->step('Failure: the lamp is unplugged');
        $ha->callService('input_boolean', 'turn_on', ['entity_id' => 'input_boolean.sim_living_room_lamp_offline']);
        $this->until('Home Helper marks it unavailable', fn () => ! $this->device('light.living_room_lamp')->available);
        $this->refused(fn () => $control->request($this->device('light.living_room_lamp'), $owner, 'turn_on'), 'isn\'t answering');
        $this->check($ha->state('light.living_room_lamp')['state'] === 'unavailable', 'and nothing was sent');
        $ha->callService('input_boolean', 'turn_off', ['entity_id' => 'input_boolean.sim_living_room_lamp_offline']);
        $this->until('plugged back in', fn () => $this->device('light.living_room_lamp')->available);

        $this->step('Failure: a wrong or revoked token');
        try {
            (new HomeAssistant(rtrim((string) $this->option('url'), '/'), 'not-a-token'))->config();
            throw new RuntimeException('a wrong token was accepted');
        } catch (HomeAssistantError $e) {
            $this->check($e->kind === 'unauthorized', 'Home Assistant refuses it: '.$e->advice());
        }

        $this->step('Failure: the webhook with a wrong secret, and a replayed old state');
        $app = rtrim((string) $this->option('app'), '/').'/api/smart-home/v1/states';
        $connection = $home->smartHomeConnection()->firstOrFail();
        $wrong = Http::withToken($connection->id.'.wrong-secret')->acceptJson()->post($app, ['entity_id' => 'lock.front_door_lock', 'state' => 'unlocked', 'last_changed' => now()->toIso8601String()]);
        $this->check($wrong->status() === 401, 'wrong secret: HTTP 401');
        $this->check($this->device('lock.front_door_lock')->state === 'locked', 'the lock stays locked in Home Helper');
        if ($this->option('webhook')) {
            // A captured push from an hour ago, replayed with the right secret
            $replay = Http::withToken((string) $this->option('webhook'))->acceptJson()->post($app, ['entity_id' => 'lock.front_door_lock', 'state' => 'unlocked', 'last_changed' => now()->subHour()->toIso8601String()]);
            $this->check($replay->successful(), "replayed old state accepted (HTTP {$replay->status()})");
            $this->check($this->device('lock.front_door_lock')->state === 'locked', 'but ignored: older than what Home Helper has');
        }
    }

    // ---- offline / recovered -------------------------------------------

    private function offline(): void
    {
        [$home, $owner, $member] = $this->household();
        $connection = $home->smartHomeConnection()->firstOrFail();
        $control = app(Control::class);

        $this->step('Home Assistant is off: a sync fails fast and the connection says so');
        $t = microtime(true);
        try {
            app(Discovery::class)->sync($connection);
            throw new RuntimeException('the sync worked with Home Assistant off');
        } catch (HomeAssistantError $e) {
            $this->check($e->kind === 'unreachable', 'unreachable: '.$e->getMessage());
        }
        // 3 s connect timeout; a stopped container's name also costs Docker's DNS ~4 s to give up on
        $this->check(microtime(true) - $t < 10, sprintf('gave up after %.1f s, not 30', microtime(true) - $t));
        $connection->refresh();
        $this->check($connection->status === 'unreachable', "connection status: {$connection->status} ({$connection->status_message})");
        $this->check(SmartHomeEvent::query()->where('household_id', $home->id)->where('type', 'hub.offline')->exists(), 'a hub.offline event for the phones');

        $this->step('The devices keep their last known state, and a button press records a failure, not a hang');
        $lamp = $this->device('light.living_room_lamp');
        $this->check($lamp->imported && $lamp->removed_at === null, 'the lamp is still there');
        $t = microtime(true);
        try {
            $row = $control->request($lamp, $owner, 'turn_on');
            $this->check($row->status === 'failed', "action failed: {$row->error}");
        } catch (ValidationException $e) {
            $this->ok('refused: '.collect($e->errors())->flatten()->first());
        }
        $this->check(microtime(true) - $t < 10, sprintf('answered in %.1f s', microtime(true) - $t));

        $this->step('An approval given while Home Assistant is off fails cleanly');
        $row = $control->request($this->device('lock.front_door_lock'), $member, 'unlock');
        $this->check($row->status === 'awaiting_approval', 'the request still waits for the owner');
        $row = $control->approve($row, $owner);
        $this->check($row->status === 'failed', "approved, then failed: {$row->error}");
    }

    private function recovered(): void
    {
        [$home] = $this->household();
        $connection = $home->smartHomeConnection()->firstOrFail();
        $since = $connection->last_error_at ?? now()->subHour();

        $this->step('Home Assistant is back: the next sync heals the connection');
        $this->until('a sync works', function () use ($connection) {
            try {
                app(Discovery::class)->sync($connection);

                return true;
            } catch (HomeAssistantError) {
                return false;
            }
        }, 120);
        $connection->refresh();
        $this->check($connection->status === 'connected', 'connected');
        $this->check(SmartHomeEvent::query()->where('household_id', $home->id)->where('type', 'hub.online')->exists(), 'a hub.online event');
        $this->until('every device answering again', fn () => SmartDevice::query()->where('household_id', $home->id)->where('imported', true)->where('available', false)->doesntExist(), 60);
        $this->check($this->device('lock.front_door_lock')->state === 'locked', 'the lock is locked (restored by Home Assistant)');

        $this->step('The restart\'s burst of unavailable → off did not pretend anything opened');
        $fake = SmartHomeEvent::query()->where('household_id', $home->id)->where('occurred_at', '>=', $since)->whereIn('type', ['door.opened', 'window.opened'])->count();
        $this->check($fake === 0, 'no opening events since Home Assistant went down');

        $this->step('And the dashboard works again');
        $row = app(Control::class)->request($this->device('light.kitchen_ceiling'), $home->users()->wherePivot('role', HouseholdRole::Owner->value)->firstOrFail(), 'toggle');
        $this->check($row->status === 'done', 'kitchen ceiling toggled');
        app(Control::class)->request($this->device('light.kitchen_ceiling'), null, 'turn_off', [], 'rule');
    }

    // ---- cleanup --------------------------------------------------------

    private function cleanUp(bool $quiet = false): void
    {
        $homes = Household::query()->where('name', $this->option('name'))->get();
        foreach ($homes as $home) {
            AutomationRule::query()->where('household_id', $home->id)->delete();
            SmartHomeAction::query()->where('household_id', $home->id)->delete();
            $home->delete();
        }
        if (! $quiet) {
            $this->ok('deleted '.$homes->count().' test household(s)');
        }
    }

    // ---- helpers --------------------------------------------------------

    /** @return array{Household, User, User} */
    private function household(): array
    {
        $home = Household::query()->where('name', $this->option('name'))->latest()->firstOrFail();
        $owner = $home->users()->wherePivot('role', HouseholdRole::Owner->value)->firstOrFail();
        $member = $home->users()->wherePivot('role', '!=', HouseholdRole::Owner->value)->firstOrFail();

        return [$home, $owner, $member];
    }

    private function token(): string
    {
        return (string) ($this->option('token') ?: getenv('HOME_ASSISTANT_TOKEN') ?: '');
    }

    private function ha(): HomeAssistant
    {
        return new HomeAssistant(rtrim((string) $this->option('url'), '/'), $this->token(), 10);
    }

    private function device(string $entityId): SmartDevice
    {
        return SmartDevice::query()->whereHas('connection.household', fn ($q) => $q->where('name', $this->option('name')))
            ->where('entity_id', $entityId)->with('room')->firstOrFail();
    }

    private function addTemplate(Household $home, User $owner, string $key): void
    {
        $suggestion = collect(Templates::suggestions($home))->firstWhere('key', $key);
        $this->check((bool) ($suggestion['ready'] ?? false), "the {$key} workflow found its devices");
        $devices = collect($suggestion['devices'])->map(fn ($list) => array_column($list, 'id'))->all();
        foreach (Templates::rules($key, $devices) as $input) {
            $rule = new AutomationRule(app(RuleValidator::class)->validate($home, $input) + ['created_by' => $owner->id, 'template' => $key]);
            $rule->household_id = $home->id;
            $rule->save();
        }
    }

    /** Wait for Home Assistant to push something; seconds it took */
    private function until(string $what, Closure $done, ?int $seconds = null): float
    {
        $start = microtime(true);
        $limit = $seconds ?? (int) $this->option('wait');
        while (! $done()) {
            if (microtime(true) - $start > $limit) {
                throw new RuntimeException("{$what}: not after {$limit} s");
            }
            usleep(250_000);
        }
        $this->ok($what);

        return microtime(true) - $start;
    }

    private function refused(Closure $call, string $message): void
    {
        try {
            $call();
        } catch (ValidationException $e) {
            $text = (string) collect($e->errors())->flatten()->first();
            $this->check(str_contains($text, $message), "refused: {$text}");

            return;
        }
        throw new RuntimeException("expected a refusal ({$message})");
    }

    private function check(bool $passed, string $what): void
    {
        if (! $passed) {
            throw new RuntimeException($what);
        }
        $this->ok($what);
    }

    private function step(string $what): void
    {
        $this->err("\n== {$what}");
    }

    private function ok(string $what): void
    {
        $this->passed++;
        $this->err("   ok {$what}");
    }

    /** Progress on stderr, so stdout carries only what a script reads */
    private function err(string $line): void
    {
        $this->getOutput()->getErrorStyle()->writeln($line);
    }
}
