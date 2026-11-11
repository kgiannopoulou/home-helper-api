<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** A note someone typed, or a document someone uploaded (docs/memory.md#notes-and-documents). */
enum NoteKind: string
{
    use HasValues;

    case Note = 'note';
    case Document = 'document';
}
