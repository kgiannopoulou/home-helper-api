<?php

namespace App\Ai;

use App\Ai\Tools\ToolRegistry;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;

/**
 * The transcript as people see it, for the API and the chat page: their
 * messages, the assistant's answers with what it looked at, events
 * ("confirmed: …") and the actions waiting for a decision. Tool results and
 * the provider's raw blocks stay on the server.
 */
class Presenter
{
    public function __construct(private ToolRegistry $tools) {}

    /** @return array<string, mixed> */
    public function conversation(AiConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title ?? 'New conversation',
            'updated_at' => $conversation->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<AiMessage>  $messages
     * @return list<array<string, mixed>>
     */
    public function messages(iterable $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if ($m->role === 'tool_results') {
                continue;
            }
            $looked = $m->role === 'assistant'
                ? array_values(array_unique(array_map(fn (array $c) => $this->tools->find($c['name'])?->label() ?? $c['name'], $m->toolCalls())))
                : [];
            $text = $m->text();
            if ($text === '' && $looked === []) {
                continue;
            }

            // One answer may take several model calls (look things up, then reply):
            // people see one message, with everything it looked at
            $last = array_key_last($out);
            if ($m->role === 'assistant' && $last !== null && $out[$last]['role'] === 'assistant') {
                $out[$last]['tools'] = array_values(array_unique([...$out[$last]['tools'], ...$looked]));
                $out[$last]['text'] = trim($out[$last]['text']."\n\n".$text);

                continue;
            }

            $out[] = [
                'id' => $m->id,
                'role' => $m->role,
                'text' => $text,
                'tools' => $looked,
                'created_at' => $m->created_at->toIso8601String(),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function action(AiPendingAction $action): array
    {
        return [
            'id' => $action->id,
            'summary' => $action->summary,
            'tool' => $action->tool,
            'status' => $action->status === 'pending' && ! $action->isOpen() ? 'expired' : $action->status,
            'expires_at' => $action->expires_at->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<AiPendingAction>  $actions
     * @return list<array<string, mixed>>
     */
    public function actions(iterable $actions): array
    {
        $out = [];
        foreach ($actions as $a) {
            $out[] = $this->action($a);
        }

        return $out;
    }
}
