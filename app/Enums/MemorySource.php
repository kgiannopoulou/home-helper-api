<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Where a piece of long-term memory came from (memory_chunks.source_type). */
enum MemorySource: string
{
    use HasValues;

    case Note = 'note';
    case Document = 'document';
    case Fact = 'fact';
    /** A summary of a conversation with the assistant (private to its person) */
    case Conversation = 'conversation';
    /** A weekly or monthly household digest */
    case Summary = 'summary';
}
