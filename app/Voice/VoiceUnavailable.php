<?php

namespace App\Voice;

use RuntimeException;

/**
 * The voice service can't be reached, refused the request, or isn't configured.
 * `status` is what the API answers: 503 when it may work later, 422 for bad audio.
 */
class VoiceUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 503, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
