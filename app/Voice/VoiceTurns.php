<?php

namespace App\Voice;

use App\Ai\Assistant;
use App\Ai\TurnOptions;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Household;
use App\Models\User;
use App\Models\VoiceSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * One spoken exchange (docs/voice.md#the-voice-turn):
 *
 *   recording → speech to text → the assistant (told to answer for the ear)
 *             → the answer made speakable → text to speech → a short-lived audio URL
 *
 * With VOICE_DRIVER=browser the browser does speech to text and speaks the
 * answer itself, so the turn starts from text and returns no audio. The
 * recording is never stored; the spoken answer is cached for a few minutes.
 */
class VoiceTurns
{
    public const NOT_HEARD = "Sorry, I didn't catch that.";

    public function __construct(
        private Assistant $assistant,
        private SpeechToText $stt,
        private TextToSpeech $tts,
    ) {}

    /**
     * @param  array{audio?: string, filename?: string, text?: string}  $input  a recording, or what the browser already heard
     * @return array{turn_id: string, heard: string, language: string|null, conversation: AiConversation|null, messages: list<AiMessage>, actions: list<AiPendingAction>, reply: string, spoken: string, audio_url: string|null, cancelled: bool}
     *
     * @throws VoiceUnavailable speech to text failed (text to speech failing only loses the audio)
     */
    public function handle(Household $household, User $user, array $input, ?AiConversation $conversation, string $turnId): array
    {
        $settings = VoiceSettings::for($user);
        $result = ['turn_id' => $turnId, 'heard' => '', 'language' => null, 'conversation' => $conversation, 'messages' => [],
            'actions' => [], 'reply' => '', 'spoken' => '', 'audio_url' => null, 'cancelled' => false];

        if (isset($input['audio'])) {
            $heard = $this->stt->transcribe($input['audio'], $input['filename'] ?? 'speech.webm', $settings->language);
        } else {
            $heard = new Transcription(trim((string) ($input['text'] ?? '')));
        }
        $result['heard'] = $heard->text;
        $result['language'] = $heard->language;

        if ($this->cancelled($user, $turnId)) {
            return ['cancelled' => true] + $result;
        }
        if ($heard->isEmpty()) {
            return $this->speakInto($result, self::NOT_HEARD, $settings, $user);
        }

        // "Confirm" / "cancel" answers the one change waiting in this conversation, hands-free
        if ($conversation && ($decided = $this->decide($conversation, $user, $heard->text))) {
            return $this->speakInto(['actions' => [$decided]] + $result, match ($decided->status) {
                'confirmed' => "Done: {$decided->summary}.",
                'declined' => 'All right, I left it.',
                default => 'That could not be done: '.($decided->result['error'] ?? 'it expired').'.',
            }, $settings, $user);
        }

        if (! $conversation) {
            $conversation = $this->assistant->start($household, $user);
            $conversation->forceFill(['channel' => 'voice'])->save();
        }
        $result['conversation'] = $conversation;

        $turn = $this->assistant->send($conversation, $user, $heard->text, new TurnOptions(
            spoken: $settings->style,
            cancelled: fn () => $this->cancelled($user, $turnId),
        ));
        $result['messages'] = $turn['messages'];
        $result['actions'] = $turn['actions'];

        $answer = trim(implode("\n\n", array_filter(array_map(fn (AiMessage $m) => $m->role === 'assistant' ? $m->text() : '', $turn['messages']))));
        if ($this->cancelled($user, $turnId)) {
            return ['cancelled' => true, 'reply' => $answer] + $result;
        }
        if ($turn['actions']) {
            $answer .= "\n\n".(count($turn['actions']) === 1 ? 'Say "confirm" or "cancel".' : 'Confirm or cancel them on the screen.');
        }

        return $this->speakInto($result, $answer, $settings, $user);
    }

    /**
     * A short yes or no while exactly one change waits for an answer. Anything
     * longer is a new question for the assistant.
     */
    private function decide(AiConversation $conversation, User $user, string $text): ?AiPendingAction
    {
        $said = trim((string) preg_replace('/[^\p{L}\s]+/u', '', mb_strtolower($text)));
        $yes = (bool) preg_match('/^(yes|yeah|yep|confirm|confirmed|do it|go ahead|please do|ok|okay)( please| it)?$/', $said);
        $no = (bool) preg_match('/^(no|nope|cancel|cancel it|dont|leave it|never mind|stop)( please| thanks)?$/', $said);
        if (! $yes && ! $no) {
            return null;
        }

        $open = $conversation->pendingActions()->where('user_id', $user->id)->where('status', 'pending')->get();
        if ($open->count() !== 1) {
            return null;
        }

        return $yes ? $this->assistant->confirm($open->sole(), $user) : $this->assistant->decline($open->sole());
    }

    /** Asked while a turn runs: the next check inside it stops it */
    public function cancel(User $user, string $turnId): void
    {
        Cache::put(self::cancelKey($user, $turnId), true, now()->addMinutes(5));
    }

    /**
     * Just speech, for "try this voice" and for reading something out again.
     */
    public function speak(User $user, string $text, ?string $voice = null, ?float $rate = null): ?string
    {
        $settings = VoiceSettings::for($user);
        if ($voice) {
            $settings->voice = $voice;
        }
        if ($rate) {
            $settings->rate = $rate;
        }

        return $this->audio($user, Speakable::make($text, $settings->style->maxSpokenChars()), $settings);
    }

    /**
     * The cached answer, once fetched through its signed URL.
     *
     * @return array{user_id: int, wav: string}|null
     */
    public static function audioFor(string $id): ?array
    {
        $cached = Cache::get("voice:audio:{$id}");

        return is_array($cached) ? ['user_id' => (int) $cached['user_id'], 'wav' => (string) base64_decode((string) $cached['wav'])] : null;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function speakInto(array $result, string $reply, VoiceSettings $settings, User $user): array
    {
        $result['reply'] = $reply;
        $result['spoken'] = Speakable::make($reply, $settings->style->maxSpokenChars());
        $result['audio_url'] = $this->audio($user, $result['spoken'], $settings);

        return $result;
    }

    private function audio(User $user, string $spoken, VoiceSettings $settings): ?string
    {
        if ($spoken === '' || config('homehelper.voice.driver') === 'browser') {
            return null; // the browser speaks it
        }

        try {
            $wav = $this->tts->speak($spoken, $settings->voiceOrDefault(), $settings->rate);
        } catch (VoiceUnavailable $e) {
            // The answer is still on the screen, and the browser can read it out
            Log::warning('Text to speech failed', ['error' => $e->getMessage()]);

            return null;
        }

        $id = (string) Str::uuid();
        $minutes = (int) config('homehelper.voice.audio_minutes');
        Cache::put("voice:audio:{$id}", ['user_id' => $user->id, 'wav' => base64_encode($wav)], now()->addMinutes($minutes));

        return URL::temporarySignedRoute('voice.audio', now()->addMinutes($minutes), ['id' => $id]);
    }

    private function cancelled(User $user, string $turnId): bool
    {
        return Cache::has(self::cancelKey($user, $turnId));
    }

    private static function cancelKey(User $user, string $turnId): string
    {
        return "voice:cancel:{$user->id}:{$turnId}";
    }
}
