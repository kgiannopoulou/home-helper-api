<?php

namespace App\Voice;

/**
 * What was said: the text, its language and how long the recording was.
 */
final readonly class Transcription
{
    public function __construct(
        public string $text,
        public ?string $language = null,
        public float $duration = 0.0,
    ) {}

    /** Nothing but silence, a cough, or Whisper's habitual "Thank you." on noise */
    public function isEmpty(): bool
    {
        $words = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', mb_strtolower($this->text)));

        return $words === '' || in_array($words, ['thank you', 'thanks for watching', 'you'], true);
    }
}
