<?php

namespace App\Ai;

use RuntimeException;

/**
 * The model can't answer right now: no API key, the provider is down or
 * rate-limited, or the request was refused upstream. The API answers 503
 * with code `ai_unavailable`; nothing the person typed is lost.
 */
class AiUnavailable extends RuntimeException
{
    public function __construct(string $message, public bool $retryable = true, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
