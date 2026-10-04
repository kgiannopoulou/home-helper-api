<?php

namespace App\Push;

use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Sends push notifications to the phone app through the Expo Push API.
 *
 * Expo answers each message with a ticket. A phone that uninstalled the app
 * answers DeviceNotRegistered, and its token is deleted so it isn't tried again.
 * A failed push never fails the job that sent it: the job's real work (the
 * shopping list, the bill) is already done, so the error is only counted.
 */
class ExpoPush
{
    /** Expo takes at most 100 messages per request. */
    private const BATCH = 100;

    /**
     * @param  iterable<User>  $users
     * @param  array<string, mixed>  $data  sent to the app with the notification, e.g. ['url' => '/shopping']
     * @return array{devices: int, sent: int, failed: int}
     */
    public function toUsers(iterable $users, string $title, string $body, array $data = []): array
    {
        $ids = collect($users)->map(fn (User $u) => $u->id)->all();
        $devices = Device::query()->whereIn('user_id', $ids)->get();

        return $this->toDevices($devices, $title, $body, $data);
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @param  array<string, mixed>  $data
     * @return array{devices: int, sent: int, failed: int}
     */
    public function toDevices(Collection $devices, string $title, string $body, array $data = []): array
    {
        $result = ['devices' => $devices->count(), 'sent' => 0, 'failed' => 0];

        foreach ($devices->chunk(self::BATCH) as $batch) {
            $batch = $batch->values();
            $messages = $batch->map(fn (Device $d) => [
                'to' => $d->expo_token,
                'title' => $title,
                'body' => $body,
                'data' => (object) $data,
                'sound' => 'default',
                'priority' => 'high',
            ])->values()->all();

            try {
                $tickets = $this->post($messages);
            } catch (ConnectionException|RequestException $e) {
                report($e);
                $result['failed'] += $batch->count();

                continue;
            }

            $gone = [];
            foreach ($batch as $i => $device) {
                $ticket = $tickets[$i] ?? null;
                if (($ticket['status'] ?? null) === 'ok') {
                    $result['sent']++;

                    continue;
                }
                $result['failed']++;
                if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    $gone[] = $device->id;
                }
            }
            if ($gone) {
                Device::query()->whereKey($gone)->delete();
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function post(array $messages): array
    {
        $request = Http::acceptJson()->asJson()->timeout(15)->retry(2, 500, throw: false);
        if ($token = config('services.expo.access_token')) {
            $request = $request->withToken($token);
        }

        return $request->post(config('services.expo.push_url'), $messages)->throw()->json('data', []);
    }
}
