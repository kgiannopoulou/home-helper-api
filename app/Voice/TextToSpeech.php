<?php

namespace App\Voice;

/**
 * Turns text into speech (docs/voice.md#text-to-speech).
 */
interface TextToSpeech
{
    /**
     * @param  float  $rate  0.5 (slow) to 2.0 (fast)
     * @return string WAV bytes
     *
     * @throws VoiceUnavailable
     */
    public function speak(string $text, string $voice, float $rate = 1.0): string;
}
