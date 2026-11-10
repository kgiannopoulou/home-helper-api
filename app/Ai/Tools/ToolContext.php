<?php

namespace App\Ai\Tools;

use App\Models\AiConversation;
use App\Models\Household;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Who is asking, about which household, on which day at home. Every tool
 * reads only this household; nothing a tool does comes from the model's
 * input except the arguments it validates.
 */
final readonly class ToolContext
{
    public function __construct(
        public Household $household,
        public User $user,
        public CarbonImmutable $today,
        public ?AiConversation $conversation = null,
    ) {}
}
