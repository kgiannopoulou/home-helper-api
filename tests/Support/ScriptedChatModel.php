<?php

namespace Tests\Support;

use App\Ai\ChatModel;
use App\Ai\ChatReply;
use App\Ai\ChatRequest;
use Closure;
use RuntimeException;

/**
 * A model that answers from a script, and remembers every request, so tests
 * can check what the assistant sent (history, tool results, context) and drive
 * it through tool calls without a network.
 *
 *   $model = ScriptedChatModel::bind([
 *       ScriptedChatModel::calls(['get_shopping_list' => []]),
 *       fn (ChatRequest $r) => ChatReply::text('Milk and bread.'),
 *   ]);
 */
class ScriptedChatModel implements ChatModel
{
    /** @var list<ChatRequest> */
    public array $requests = [];

    /**
     * @param  list<ChatReply|Closure(ChatRequest): ChatReply>  $script
     */
    public function __construct(private array $script, private string $name = 'scripted') {}

    /** @param list<ChatReply|Closure(ChatRequest): ChatReply> $script */
    public static function bind(array $script, string $name = 'scripted'): self
    {
        $model = new self($script, $name);
        app()->instance(ChatModel::class, $model);

        return $model;
    }

    /** A reply that calls these tools: ['tool_name' => input, …] */
    public static function calls(array $calls, string $text = ''): ChatReply
    {
        $blocks = $text === '' ? [] : [['type' => 'text', 'text' => $text]];
        $i = 0;
        foreach ($calls as $name => $input) {
            $blocks[] = ['type' => 'tool_call', 'id' => 'call_'.(++$i).'_'.$name, 'name' => preg_replace('/#\d+$/', '', $name), 'input' => $input];
        }

        return new ChatReply($blocks, 'tool_use', 'scripted-model', usage: ['input' => 100, 'output' => 20]);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function reply(ChatRequest $request): ChatReply
    {
        $this->requests[] = $request;
        $next = array_shift($this->script) ?? throw new RuntimeException('The script ran out after '.count($this->requests).' requests.');

        return $next instanceof Closure ? $next($request) : $next;
    }

    /** The tool results the assistant sent in the latest request, by tool name */
    public function lastToolResults(): array
    {
        $last = $this->requests[array_key_last($this->requests) ?? 0] ?? null;
        $message = $last?->transcript[array_key_last($last->transcript) ?? 0] ?? null;
        $out = [];
        foreach ($message?->content['results'] ?? [] as $r) {
            $out[$r['name']] = $r;
        }

        return $out;
    }
}
