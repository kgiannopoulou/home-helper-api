<?php

namespace App\SmartHome;

use App\Models\Household;
use App\Models\SmartHomeConnection;
use App\Models\User;
use App\Policies\SmartHomePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a household to its Home Assistant, and keeping track of whether
 * it answers (docs/smart-home.md#connecting).
 *
 * Every call to Home Assistant anywhere in the app reports back here
 * (succeeded / failed), so the connection's status is always what the last
 * real call found, not what a check five minutes ago found.
 */
class Connections
{
    /**
     * Connect, or replace the existing connection: the token is checked
     * against Home Assistant first, so a typo never gets saved.
     *
     * @return array{0: SmartHomeConnection, 1: string} the connection and its webhook secret (shown once)
     *
     * @throws ValidationException
     */
    public function connect(Household $household, User $user, string $url, string $token): array
    {
        if (! (new SmartHomePolicy)->manage($user, $household)) {
            throw ValidationException::withMessages(['url' => 'Only the household owner can connect Home Assistant.']);
        }
        $url = self::normalizeUrl($url);
        $token = trim($token);

        try {
            $config = $this->clientFor($url, $token)->config();
        } catch (HomeAssistantError $e) {
            throw ValidationException::withMessages([$e->kind === 'unauthorized' ? 'token' : 'url' => $e->advice()]);
        }

        $secret = Str::random(48);
        $connection = SmartHomeConnection::query()->firstOrNew(['household_id' => $household->id]);
        $connection->household_id = $household->id;
        $connection->forceFill([
            'connected_by' => $user->id,
            'base_url' => $url,
            'token' => $token,
            'webhook_secret_hash' => hash('sha256', $secret),
            'status' => 'connected',
            'status_message' => null,
            'location_name' => mb_substr($config['location_name'], 0, 120),
            'ha_version' => mb_substr($config['version'], 0, 30),
            'time_zone' => $config['time_zone'],
            'last_ok_at' => CarbonImmutable::now(),
        ])->save();

        Log::info('Home Assistant connected', ['household_id' => $household->id, 'user_id' => $user->id, 'version' => $config['version']]);

        return [$connection, $secret];
    }

    /** Ask Home Assistant again ("Check connection", and the scheduled sync) */
    public function check(SmartHomeConnection $connection): SmartHomeConnection
    {
        try {
            $config = $this->client($connection)->config();
            $connection->forceFill([
                'location_name' => mb_substr($config['location_name'], 0, 120),
                'ha_version' => mb_substr($config['version'], 0, 30),
                'time_zone' => $config['time_zone'],
            ]);
            $this->succeeded($connection);
        } catch (HomeAssistantError $e) {
            $this->failed($connection, $e);
        }

        return $connection;
    }

    /** A new webhook secret; the old one stops working at once */
    public function rotateSecret(SmartHomeConnection $connection): string
    {
        $secret = Str::random(48);
        $connection->forceFill(['webhook_secret_hash' => hash('sha256', $secret)])->save();

        return $secret;
    }

    public function client(SmartHomeConnection $connection): HomeAssistant
    {
        return $this->clientFor($connection->base_url, $connection->token);
    }

    public function succeeded(SmartHomeConnection $connection): void
    {
        $connection->forceFill(['status' => 'connected', 'status_message' => null, 'last_ok_at' => CarbonImmutable::now()])->save();
    }

    public function failed(SmartHomeConnection $connection, HomeAssistantError $e): void
    {
        $wasConnected = $connection->isConnected();
        $connection->forceFill([
            'status' => $e->status(),
            'status_message' => mb_substr($e->advice(), 0, 300),
            'last_error_at' => CarbonImmutable::now(),
        ])->save();

        if ($wasConnected) {
            Log::warning('Home Assistant call failed', ['household_id' => $connection->household_id, 'kind' => $e->kind, 'error' => $e->getMessage()]);
        }
    }

    /** The connection for "Bearer <id>.<secret>" from Home Assistant, or null */
    public function forWebhook(?string $bearer): ?SmartHomeConnection
    {
        if (! $bearer || ! preg_match('/^([0-9a-hjkmnp-tv-z]{26})\.([A-Za-z0-9]{48})$/i', $bearer, $m)) {
            return null;
        }
        $connection = SmartHomeConnection::query()->find(strtolower($m[1]));

        return $connection && hash_equals($connection->webhook_secret_hash, hash('sha256', $m[2])) ? $connection : null;
    }

    /**
     * "192.168.1.20:8123/" -> "http://192.168.1.20:8123". Only a scheme, host
     * and port: the token must never be sent to a path someone typed in.
     *
     * @throws ValidationException
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (! preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
            $url = 'http://'.$url;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $path = $parts['path'] ?? '';

        if (! $parts || ! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($path !== '' && $path !== '/')) {
            throw ValidationException::withMessages(['url' => 'Use just the address, like http://192.168.1.20:8123 or https://home.example.com.']);
        }

        return $scheme.'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function clientFor(string $url, string $token): HomeAssistant
    {
        return new HomeAssistant($url, $token, (int) config('homehelper.smart_home.timeout_seconds'));
    }
}
