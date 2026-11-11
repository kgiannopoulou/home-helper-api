<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesVoice;
use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Voice\VoicePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Talking to the assistant over the API (docs/voice.md#api), for the phone.
 */
class VoiceController extends Controller
{
    use HandlesVoice;

    /** Your voice settings, the voices and styles, and a wake-word ticket when it is on */
    public function show(Request $request, VoicePresenter $present): JsonResponse
    {
        return response()->json($present->config($request->user()));
    }

    /**
     * One spoken turn: multipart `audio` (or `text` the device already
     * recognised), `turn_id` (yours, to cancel it), optional `conversation_id`.
     */
    public function turn(Request $request, Household $household): JsonResponse
    {
        return $this->voiceTurn($request, $household);
    }

    /** Stop a turn that is still running: it ends at its next step */
    public function cancel(Request $request, Household $household, string $turn): JsonResponse
    {
        return $this->voiceCancel($request, $turn);
    }

    /** Read a text out in your voice (or try another one) */
    public function speak(Request $request): JsonResponse
    {
        return $this->voiceSpeak($request);
    }

    public function update(Request $request): JsonResponse
    {
        return $this->voiceSaveSettings($request);
    }

    /** Delete all your voice conversations */
    public function forget(Request $request): JsonResponse
    {
        return $this->voiceForget($request->user());
    }
}
