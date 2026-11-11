<?php

namespace App\Voice;

/**
 * Turns a recording into text (docs/voice.md#speech-to-text).
 */
interface SpeechToText
{
    /**
     * @param  string  $audio  the recording's bytes (WebM/Opus, Ogg, MP4 or WAV)
     *
     * @throws VoiceUnavailable
     */
    public function transcribe(string $audio, string $filename, ?string $language = null): Transcription;
}
