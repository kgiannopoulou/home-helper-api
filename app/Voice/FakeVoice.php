<?php

namespace App\Voice;

/**
 * Speech without models, for tests and the offline demo (VOICE_DRIVER=fake):
 * a "recording" whose bytes start with TEXT: says the rest, and speaking
 * produces a short, real WAV. The same convention as the voice service's own
 * fake engines, so both sides are tested the same way.
 */
class FakeVoice implements SpeechToText, TextToSpeech
{
    /** @var list<array{text: string, voice: string, rate: float}> what was spoken, for tests */
    public array $spoken = [];

    public bool $down = false;

    public function transcribe(string $audio, string $filename, ?string $language = null): Transcription
    {
        $this->failIfDown();
        $text = str_starts_with($audio, 'TEXT:') ? trim(substr($audio, 5)) : '';

        return new Transcription($text, $language ?? 'en', round(str_word_count($text) * 0.4, 2));
    }

    public function speak(string $text, string $voice, float $rate = 1.0): string
    {
        $this->failIfDown();
        $this->spoken[] = ['text' => $text, 'voice' => $voice, 'rate' => $rate];

        return self::wav(0.2);
    }

    private function failIfDown(): void
    {
        if ($this->down) {
            throw new VoiceUnavailable('The voice service is not reachable.');
        }
    }

    /** A silent mono 16-bit WAV of this many seconds */
    public static function wav(float $seconds, int $rate = 16000): string
    {
        $data = str_repeat("\0\0", (int) ($seconds * $rate));

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            .'data'.pack('V', strlen($data)).$data;
    }
}
