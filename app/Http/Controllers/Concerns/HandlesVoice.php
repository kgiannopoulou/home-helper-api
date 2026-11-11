<?php

namespace App\Http\Controllers\Concerns;

use App\Ai\AiUnavailable;
use App\Ai\AssistantRefused;
use App\Models\AiConversation;
use App\Models\Household;
use App\Models\User;
use App\Models\VoiceSettings;
use App\Voice\ResponseStyle;
use App\Voice\VoicePresenter;
use App\Voice\VoiceTurns;
use App\Voice\VoiceUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The voice endpoints, shared by the API (token, household in the URL) and
 * the page (session, household from EnsureHousehold): both answer JSON.
 */
trait HandlesVoice
{
    protected function voiceTurn(Request $request, Household $household): JsonResponse
    {
        $data = $request->validate([
            'audio' => ['required_without:text', 'file', 'max:'.config('homehelper.voice.max_upload_kb')],
            'text' => ['required_without:audio', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string'],
            'turn_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);
        $conversation = isset($data['conversation_id'])
            ? AiConversation::query()->where('household_id', $household->id)->where('user_id', $request->user()->id)->findOrFail($data['conversation_id'])
            : null;

        $input = isset($data['audio'])
            ? ['audio' => (string) $request->file('audio')?->get(), 'filename' => $request->file('audio')?->getClientOriginalName() ?: 'speech.webm']
            : ['text' => $data['text']];

        try {
            $turn = app(VoiceTurns::class)->handle($household, $request->user(), $input, $conversation, $data['turn_id']);
        } catch (VoiceUnavailable $e) {
            return $this->voiceError($request, $e->status, $e->status === 503 ? 'voice_unavailable' : 'audio_unusable', $e->getMessage());
        } catch (AssistantRefused $e) {
            return $this->voiceError($request, $e->status, $e->errorCode, $e->getMessage());
        } catch (AiUnavailable $e) {
            report($e);

            return $this->voiceError($request, 503, 'ai_unavailable', $e->getMessage());
        }

        return response()->json(app(VoicePresenter::class)->turn($turn));
    }

    protected function voiceCancel(Request $request, string $turn): JsonResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9-]{1,64}$/', $turn) === 1, 404);
        app(VoiceTurns::class)->cancel($request->user(), $turn);

        return response()->json(['turn_id' => $turn, 'cancelling' => true], 202);
    }

    protected function voiceSpeak(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:1000'],
            'voice' => ['nullable', Rule::in((array) config('homehelper.voice.voices'))],
            'rate' => ['nullable', 'numeric', 'between:0.5,2'],
        ]);

        return response()->json(['audio_url' => app(VoiceTurns::class)->speak($request->user(), $data['text'], $data['voice'] ?? null, isset($data['rate']) ? (float) $data['rate'] : null)]);
    }

    protected function voiceSaveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'voice' => ['sometimes', 'nullable', Rule::in((array) config('homehelper.voice.voices'))],
            'style' => ['sometimes', Rule::enum(ResponseStyle::class)],
            'rate' => ['sometimes', 'numeric', 'between:0.5,2'],
            'language' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z]{2}$/'],
            'wake_word' => ['sometimes', 'boolean'],
            'continuous' => ['sometimes', 'boolean'],
            'keep_days' => ['sometimes', 'nullable', Rule::in((array) config('homehelper.voice.keep_days_options'))],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $settings = VoiceSettings::for($request->user());
        $settings->fill($data)->save();

        return response()->json(app(VoicePresenter::class)->config($request->user()));
    }

    /**
     * Deletes every voice conversation this person had, in every household,
     * with their summaries in memory (docs/voice.md#privacy).
     */
    protected function voiceForget(User $user): JsonResponse
    {
        $deleted = 0;
        AiConversation::query()->where('user_id', $user->id)->where('channel', 'voice')->each(function (AiConversation $c) use (&$deleted) {
            $c->delete();
            $deleted++;
        });

        return response()->json(['deleted_conversations' => $deleted]);
    }

    private function voiceError(Request $request, int $status, string $code, string $message): JsonResponse
    {
        $id = (string) $request->attributes->get('request_id');

        return response()->json(['message' => $message, 'code' => $code, 'request_id' => $id], $status)->header('X-Request-Id', $id);
    }
}
