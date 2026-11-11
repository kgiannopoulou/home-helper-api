<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\HandlesVoice;
use App\Voice\VoicePresenter;
use App\Voice\VoiceTurns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Voice on the dashboard (docs/voice.md). The assistant page records and
 * plays audio itself, so these answer JSON (with the session and CSRF, like
 * every page), and /settings/voice is an Inertia page.
 */
class VoiceController extends WebController
{
    use HandlesVoice;

    public function turn(Request $request): JsonResponse
    {
        return $this->voiceTurn($request, $this->household($request));
    }

    public function cancel(Request $request, string $turn): JsonResponse
    {
        return $this->voiceCancel($request, $turn);
    }

    public function speak(Request $request): JsonResponse
    {
        return $this->voiceSpeak($request);
    }

    public function config(Request $request, VoicePresenter $present): JsonResponse
    {
        return response()->json($present->config($request->user()));
    }

    public function settings(Request $request, VoicePresenter $present): InertiaResponse
    {
        return Inertia::render('settings/voice', ['voice' => $present->config($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        return $this->voiceSaveSettings($request);
    }

    public function forget(Request $request): JsonResponse
    {
        return $this->voiceForget($request->user());
    }

    /**
     * A spoken answer, by the signed URL the turn returned. Only its own person
     * may play it, and it disappears after a few minutes.
     */
    public function audio(Request $request, string $id): Response
    {
        $audio = VoiceTurns::audioFor($id);
        abort_if($audio === null, 404);
        abort_if($request->user() && $request->user()->id !== $audio['user_id'], 404);

        return response($audio['wav'], 200, [
            'Content-Type' => 'audio/wav',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
