<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The phone registers its Expo push token here after logging in.
 */
class DeviceController extends Controller
{
    /**
     * Saves the token for this user and this login. A token is one phone: if the
     * phone was registered before (by someone else who logged out, or another
     * login), it moves to whoever registers it now.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[[^\]]+\]$/'],
            'platform' => ['sometimes', 'nullable', 'in:ios,android'],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $accessToken = $request->user()->currentAccessToken();
        $device = Device::firstOrNew(['expo_token' => $data['token']]);
        $device->fill(['platform' => $data['platform'] ?? null, 'name' => $data['name'] ?? null]);
        $device->user_id = $request->user()->id;
        $device->personal_access_token_id = $accessToken instanceof PersonalAccessToken ? $accessToken->id : null;
        $created = ! $device->exists;
        $device->save();

        return response()->json(['data' => ['id' => $device->id, 'platform' => $device->platform, 'name' => $device->name]], $created ? 201 : 200);
    }

    /**
     * Turns pushes off for this phone. Logging out does this too.
     */
    public function destroy(Request $request): Response
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);
        $request->user()->devices()->where('expo_token', $data['token'])->delete();

        return response()->noContent();
    }
}
