<?php

namespace App\Ai;

use RuntimeException;

/**
 * The assistant won't take this message now: the conversation is busy with
 * another reply, it is full, or the person used today's messages. Carries the
 * HTTP status and error code the API answers with.
 */
class AssistantRefused extends RuntimeException
{
    public function __construct(string $message, public int $status, public string $errorCode)
    {
        parent::__construct($message);
    }
}
