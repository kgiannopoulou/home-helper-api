<?php

namespace App\Voice;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The voice service over HTTP (services/voice): speech to text with
 * faster-whisper and text to speech with Piper, on the household's own machine.
 */
class VoiceServiceClient implements SpeechToText, TextToSpeech
{
    public function __construct(
        private string $url,
        private ?string $token,
        private int $timeout = 60,
    ) {}

    public function transcribe(string $audio, string $filename, ?string $language = null): Transcription
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->attach('audio', $audio, $filename)
            ->post($this->url.'/v1/stt', array_filter(['language' => $language])));

        return new Transcription(
            text: trim((string) $response->json('text', '')),
            language: $response->json('language'),
            duration: (float) $response->json('duration', 0),
        );
    }

    public function speak(string $text, string $voice, float $rate = 1.0): string
    {
        return $this->send(fn (PendingRequest $http) => $http->asJson()
            ->post($this->url.'/v1/tts', ['text' => $text, 'voice' => $voice, 'rate' => $rate]))->body();
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    private function send(callable $request): Response
    {
        if (! $this->token) {
            throw new VoiceUnavailable('The voice service needs VOICE_SERVICE_TOKEN.');
        }

        try {
            $response = $request(Http::withToken($this->token)->timeout($this->timeout)->acceptJson());
        } catch (ConnectionException $e) {
            throw new VoiceUnavailable('The voice service is not reachable.', previous: $e);
        }

        if ($response->status() === 422 || $response->status() === 413) {
            throw new VoiceUnavailable((string) $response->json('detail', 'The audio could not be used.'), 422);
        }
        if (! $response->successful()) {
            throw new VoiceUnavailable("The voice service answered {$response->status()}.");
        }

        return $response;
    }
}
