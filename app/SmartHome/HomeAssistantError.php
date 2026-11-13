<?php

namespace App\SmartHome;

use RuntimeException;
use Throwable;

/**
 * Home Assistant didn't do what was asked. The kind says whose problem it is:
 *
 *   unreachable   no answer: the Pi is off, the network is down, a timeout
 *   unauthorized  the token was revoked or is wrong (401/403)
 *   not_found     no such entity or service (404); also "that's not Home Assistant"
 *   rejected      Home Assistant refused the call (400): bad data for that service
 *   error         anything else (5xx, a reply that isn't JSON)
 */
class HomeAssistantError extends RuntimeException
{
    public const KINDS = ['unreachable', 'unauthorized', 'not_found', 'rejected', 'error'];

    public function __construct(public readonly string $kind, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** For people: what to do about it */
    public function advice(): string
    {
        return match ($this->kind) {
            'unreachable' => 'Home Assistant is not answering. Is it switched on, and can this server reach its address?',
            'unauthorized' => 'Home Assistant refused the token. Create a new long-lived access token and connect again.',
            'not_found' => 'Home Assistant doesn\'t know that device or action any more. Sync devices to refresh the list.',
            'rejected' => 'Home Assistant refused this: '.$this->getMessage(),
            default => 'Home Assistant had a problem: '.$this->getMessage(),
        };
    }

    /** The connection's status after this error */
    public function status(): string
    {
        return match ($this->kind) {
            'unreachable' => 'unreachable',
            'unauthorized' => 'unauthorized',
            default => 'error',
        };
    }
}
