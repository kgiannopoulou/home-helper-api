<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A Home Assistant REST API in memory, behind Http::fake(): states, areas,
 * service calls that change them, and switches for the failures a real one
 * has (off, token revoked, slow, broken). The same entities as the test home
 * (ops/home-assistant/config/packages/test_home.yaml), so the Pest tests and
 * the end-to-end run against the real one agree.
 */
class FakeHomeAssistant
{
    public const URL = 'http://ha.test:8123';

    public const TOKEN = 'test-long-lived-access-token-0123456789';

    /** @var array<string, array{entity_id: string, state: string, attributes: array<string, mixed>, last_changed: string}> */
    public array $states = [];

    /** @var array<string, string> entity_id => area name */
    public array $areas = [];

    /** @var list<array{domain: string, service: string, data: array<string, mixed>}> */
    public array $calls = [];

    /** null, or what goes wrong: 'unreachable', 'unauthorized', 'server_error', 'not_json' */
    public ?string $failure = null;

    public static function install(): self
    {
        $ha = new self;
        $ha->seed();
        Http::fake([self::URL.'/*' => fn (Request $r) => $ha->handle($r)]);

        return $ha;
    }

    public function seed(): void
    {
        $this->states = [];
        $add = function (string $id, string $state, array $attributes, string $area) {
            $this->set($id, $state, $attributes);
            $this->areas[$id] = $area;
        };
        $add('light.living_room_lamp', 'off', ['friendly_name' => 'Living room lamp', 'supported_color_modes' => ['brightness'], 'brightness' => null], 'Living Room');
        $add('light.bedroom_lamp', 'off', ['friendly_name' => 'Bedroom lamp', 'supported_color_modes' => ['brightness'], 'brightness' => null], 'Bedroom');
        $add('light.kitchen_ceiling', 'off', ['friendly_name' => 'Kitchen ceiling', 'supported_color_modes' => ['onoff']], 'Kitchen');
        $add('switch.hallway_light', 'off', ['friendly_name' => 'Hallway light'], 'Hallway');
        $add('switch.dehumidifier_plug', 'off', ['friendly_name' => 'Dehumidifier plug'], 'Bathroom');
        $add('switch.coffee_machine_plug', 'off', ['friendly_name' => 'Coffee machine plug'], 'Kitchen');
        foreach (['living_room' => ['Living Room', 21.5, 48], 'bathroom' => ['Bathroom', 23.0, 55], 'bedroom' => ['Bedroom', 19.5, 50]] as $key => [$area, $t, $h]) {
            $name = ucfirst(str_replace('_', ' ', $key));
            $add("sensor.{$key}_temperature", (string) $t, ['friendly_name' => "{$name} temperature", 'device_class' => 'temperature', 'unit_of_measurement' => '°C', 'state_class' => 'measurement'], $area);
            $add("sensor.{$key}_humidity", (string) $h, ['friendly_name' => "{$name} humidity", 'device_class' => 'humidity', 'unit_of_measurement' => '%', 'state_class' => 'measurement'], $area);
        }
        $add('binary_sensor.front_door', 'off', ['friendly_name' => 'Front door', 'device_class' => 'door'], 'Hallway');
        $add('binary_sensor.balcony_door', 'off', ['friendly_name' => 'Balcony door', 'device_class' => 'door'], 'Living Room');
        $add('binary_sensor.bedroom_window', 'off', ['friendly_name' => 'Bedroom window', 'device_class' => 'window'], 'Bedroom');
        $add('binary_sensor.hallway_motion', 'off', ['friendly_name' => 'Hallway motion', 'device_class' => 'motion'], 'Hallway');
        $add('lock.front_door_lock', 'locked', ['friendly_name' => 'Front door lock', 'supported_features' => 0], 'Hallway');
        // Things Home Helper doesn't import
        $add('sun.sun', 'below_horizon', ['friendly_name' => 'Sun', 'next_rising' => '2026-11-14T05:20:00+00:00'], '');
        $add('person.owner', 'home', ['friendly_name' => 'Owner'], '');
        $add('sensor.sun_next_dawn', '2026-11-14T04:52:00+00:00', ['friendly_name' => 'Sun Next dawn', 'device_class' => 'timestamp'], '');
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(string $entityId, string $state, array $attributes = []): void
    {
        $old = $this->states[$entityId] ?? null;
        $this->states[$entityId] = [
            'entity_id' => $entityId,
            'state' => $state,
            'attributes' => $attributes + ($old['attributes'] ?? []),
            'last_changed' => $old && $old['state'] === $state ? $old['last_changed'] : CarbonImmutable::now()->toIso8601ZuluString('microsecond'),
        ];
    }

    public function remove(string $entityId): void
    {
        unset($this->states[$entityId], $this->areas[$entityId]);
    }

    public function handle(Request $request): mixed
    {
        $failure = match ($this->failure) {
            'unreachable' => fn () => Http::failedConnection('cURL error 7: Failed to connect to ha.test port 8123'),
            'unauthorized' => fn () => Http::response('401: Unauthorized', 401),
            'server_error' => fn () => Http::response('500 Internal Server Error', 500),
            'not_json' => fn () => Http::response('<html>Router login</html>', 200, ['Content-Type' => 'text/html']),
            default => null,
        };
        if ($failure) {
            return $failure();
        }
        if ($request->header('Authorization') !== ['Bearer '.self::TOKEN]) {
            return Http::response('401: Unauthorized', 401);
        }

        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $method = $request->method();

        return (match (true) {
            $method === 'GET' && $path === '/api/config' => fn () => Http::response(['location_name' => 'Home Helper test home', 'version' => '2026.11.1', 'time_zone' => 'Europe/Athens', 'unit_system' => ['temperature' => '°C']]),
            $method === 'GET' && $path === '/api/states' => fn () => Http::response(array_values($this->states)),
            $method === 'GET' && str_starts_with($path, '/api/states/') => function () use ($path) {
                $id = rawurldecode(substr($path, strlen('/api/states/')));

                return isset($this->states[$id]) ? Http::response($this->states[$id]) : Http::response(['message' => 'Entity not found.'], 404);
            },
            $method === 'POST' && $path === '/api/template' => fn () => Http::response($this->renderAreas($request->data()['template'] ?? ''), 200, ['Content-Type' => 'text/plain']),
            $method === 'POST' && str_starts_with($path, '/api/services/') => fn () => $this->service($path, $request->data()),
            default => fn () => Http::response(['message' => 'Not found'], 404),
        })();
    }

    /** The areas template App\SmartHome\Discovery sends: answer with JSON of entity_id => area */
    private function renderAreas(string $template): string
    {
        if (! str_contains($template, 'area_name')) {
            return '';
        }

        return json_encode(array_filter($this->areas, fn ($a) => $a !== ''), JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** @param  array<string, mixed>  $data */
    private function service(string $path, array $data): mixed
    {
        [, , , $domain, $service] = explode('/', $path) + [null, null, null, null, null];
        $id = (string) ($data['entity_id'] ?? '');
        $this->calls[] = ['domain' => $domain, 'service' => $service, 'data' => $data];

        if (! isset($this->states[$id]) || ! str_starts_with($id, $domain.'.')) {
            return Http::response(['message' => "Entity {$id} not found"], 400);
        }
        if (($this->states[$id]['state'] ?? null) === 'unavailable') {
            // What Home Assistant does for an unplugged device: the call "works", nothing changes
            return Http::response([]);
        }

        match ($service) {
            'turn_on' => $this->set($id, 'on', isset($data['brightness_pct'])
                ? ['brightness' => (int) round(255 * $data['brightness_pct'] / 100)]
                : ($domain === 'light' && in_array('brightness', $this->states[$id]['attributes']['supported_color_modes'] ?? [], true) ? ['brightness' => $this->states[$id]['attributes']['brightness'] ?? 255] : [])),
            'turn_off' => $this->set($id, 'off', $domain === 'light' ? ['brightness' => null] : []),
            'toggle' => $this->set($id, $this->states[$id]['state'] === 'on' ? 'off' : 'on'),
            'lock' => $this->set($id, 'locked'),
            'unlock' => $this->set($id, 'unlocked'),
            default => null,
        };

        return Http::response([$this->states[$id]]);
    }
}
