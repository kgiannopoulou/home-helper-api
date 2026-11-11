<?php

namespace App\Voice;

/**
 * VOICE_DRIVER=browser: no voice service. The browser recognises speech and
 * sends text, and speaks the answer with its own voices, so the server is
 * never asked to do either.
 */
class BrowserSpeech implements SpeechToText, TextToSpeech
{
    public function transcribe(string $audio, string $filename, ?string $language = null): Transcription
    {
        throw new VoiceUnavailable('This server has no voice service: send what the browser heard as text.', 422);
    }

    public function speak(string $text, string $voice, float $rate = 1.0): string
    {
        throw new VoiceUnavailable('This server has no voice service: the browser speaks the answer.', 422);
    }
}
