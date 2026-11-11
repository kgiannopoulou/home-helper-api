<?php

namespace App\Memory;

use App\Models\HouseholdFact;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Facts and notes as the API and the Memory page show them.
 */
class MemoryPresenter
{
    /** @return array<string, mixed> */
    public static function fact(HouseholdFact $f, User $viewer): array
    {
        return [
            'id' => $f->id,
            'kind' => $f->kind->value,
            'content' => $f->content,
            'about' => $f->about ? ['id' => $f->about->id, 'name' => $f->about->name] : null,
            'added_by' => $f->user?->name,
            'source' => $f->source,
            'private' => $f->private,
            'mine' => $f->user_id === $viewer->id,
            'created_at' => $f->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function note(Note $n, User $viewer, bool $withBody = false): array
    {
        return [
            'id' => $n->id,
            'kind' => $n->kind->value,
            'title' => $n->title,
            'excerpt' => Str::limit(preg_replace('/\s+/', ' ', $n->body) ?? '', 200),
            'file' => $n->file_path ? ['name' => $n->file_name, 'mime' => $n->mime, 'size' => $n->size] : null,
            'added_by' => $n->user?->name,
            'private' => $n->private,
            'mine' => $n->user_id === $viewer->id,
            'created_at' => $n->created_at->toIso8601String(),
            'updated_at' => $n->updated_at->toIso8601String(),
        ] + ($withBody ? ['body' => $n->body] : []);
    }
}
