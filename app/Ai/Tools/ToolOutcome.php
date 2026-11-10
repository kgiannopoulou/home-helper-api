<?php

namespace App\Ai\Tools;

/**
 * What came of one tool call, as the model will read it.
 */
final readonly class ToolOutcome
{
    /**
     * @param  array<string, mixed>  $content
     */
    public function __construct(
        public array $content,
        public bool $isError = false,
        public ?string $pendingActionId = null,
    ) {}

    /** @param array<string, mixed> $details */
    public static function error(string $message, array $details = []): self
    {
        return new self(['error' => $message] + $details, isError: true);
    }
}
