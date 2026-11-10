<?php

namespace App\Ai\Providers;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use App\Ai\AiUnavailable;
use App\Ai\ChatModel;
use App\Ai\ChatReply;
use App\Ai\ChatRequest;
use App\Models\AiMessage;
use Psr\Http\Client\ClientInterface;
use stdClass;

/**
 * Claude, through the official Anthropic PHP SDK (anthropic-ai/sdk).
 *
 * - Model and effort from config (homehelper.ai): Claude Opus 5.5 at medium effort.
 *   Thinking is adaptive (always on for this model); its blocks are stored and
 *   sent back unchanged.
 * - Prompt caching: the tools and the fixed instructions are one cached prefix
 *   (breakpoint on the instructions); the household context comes after it, and
 *   the conversation is cached as it grows (top-level automatic breakpoint).
 * - Refusals: server-side fallback ("default") lets the API retry a declined
 *   request on its fallback model; a turn that is still refused says so.
 * - The SDK retries 429/5xx/connection errors twice; what still fails becomes
 *   AiUnavailable (503 for the API).
 */
class ClaudeChatModel implements ChatModel
{
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(
        private ?string $apiKey,
        private string $model,
        private string $effort,
        private int $maxTokens,
        private int $timeout = 120,
        /** A PSR-18 client instead of the network (tests) */
        private ?ClientInterface $transporter = null,
    ) {}

    public function name(): string
    {
        return 'claude';
    }

    public function reply(ChatRequest $request): ChatReply
    {
        if (! $this->apiKey) {
            throw new AiUnavailable('The assistant needs ANTHROPIC_API_KEY (or AI_PROVIDER=offline for the keyword demo).', retryable: false);
        }

        try {
            $message = $this->client()->beta->messages->create(...$this->params($request));
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw new AiUnavailable('The Anthropic API key was refused.', retryable: false, previous: $e);
        } catch (BadRequestException $e) {
            throw new AiUnavailable('The model rejected the request: '.$e->getMessage(), retryable: false, previous: $e);
        } catch (APIStatusException|APIConnectionException $e) {
            throw new AiUnavailable('The model is not reachable right now. Try again in a moment.', previous: $e);
        } catch (APIException $e) {
            throw new AiUnavailable('The model call failed: '.$e->getMessage(), previous: $e);
        }

        return $this->parse($message);
    }

    /**
     * The named arguments for $client->beta->messages->create(). Public so the
     * request shape can be tested without a network.
     *
     * @return array<string, mixed>
     */
    public function params(ChatRequest $request): array
    {
        return [
            'model' => $this->model,
            'maxTokens' => $this->maxTokens,
            'system' => [
                // Fixed text first: with the tools before it, one cached prefix
                ['type' => 'text', 'text' => $request->instructions, 'cacheControl' => ['type' => 'ephemeral']],
                ['type' => 'text', 'text' => $request->context],
            ],
            'messages' => $this->messages($request->transcript),
            'tools' => array_map(fn (array $t) => [
                'name' => $t['name'],
                'description' => $t['description'],
                'inputSchema' => $t['input_schema'],
            ], $request->tools),
            'outputConfig' => ['effort' => $this->effort],
            // Caches the conversation so far, so each turn re-reads it at the cache price
            'cacheControl' => ['type' => 'ephemeral'],
            'betas' => [self::FALLBACK_BETA],
            'fallbacks' => 'default',
        ];
    }

    /**
     * The stored transcript as Messages API turns. Append-only: earlier turns
     * render the same way every time, so the cache and the thinking blocks hold.
     *
     * @param  list<AiMessage>  $transcript
     * @return list<array{role: string, content: mixed}>
     */
    public function messages(array $transcript): array
    {
        $turns = [];
        foreach ($transcript as $message) {
            $turn = match ($message->role) {
                'user' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => $message->text()]]],
                // Something that happened between turns (a confirmed change): the model hears it from the person's side
                'event' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => '[Home Helper] '.$message->text()]]],
                'assistant' => ['role' => 'assistant', 'content' => $this->assistantContent($message)],
                'tool_results' => ['role' => 'user', 'content' => array_map(fn (array $r) => [
                    'type' => 'tool_result',
                    'toolUseID' => $r['tool_call_id'],
                    'content' => (string) json_encode($r['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'isError' => (bool) $r['is_error'],
                ], $message->content['results'] ?? [])],
                default => null,
            };
            if ($turn !== null && $turn['content'] !== []) {
                $turns[] = $turn;
            }
        }

        return $turns;
    }

    /** @return list<mixed> */
    private function assistantContent(AiMessage $message): array
    {
        $raw = $message->content['raw'] ?? [];
        if (($message->content['provider'] ?? null) === $this->name() && $raw !== []) {
            // Rebuilt into SDK blocks so thinking signatures, fallback blocks and
            // tool_use ids go back exactly as Claude sent them
            return BetaMessage::fromArray([
                'id' => 'stored', 'type' => 'message', 'role' => 'assistant', 'model' => $this->model,
                'stop_reason' => null, 'stop_sequence' => null,
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                'content' => $raw,
            ])->content;
        }

        // Written by another provider: only the neutral blocks
        return array_values(array_map(fn (array $b) => $b['type'] === 'text'
            ? ['type' => 'text', 'text' => $b['text']]
            : ['type' => 'tool_use', 'id' => $b['id'], 'name' => $b['name'], 'input' => $b['input'] ?: new stdClass],
            array_filter($message->content['blocks'] ?? [], fn (array $b) => $b['type'] !== 'text' || $b['text'] !== '')));
    }

    public function parse(BetaMessage $message): ChatReply
    {
        $blocks = [];
        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock && trim($block->text) !== '') {
                $blocks[] = ['type' => 'text', 'text' => $block->text];
            } elseif ($block instanceof BetaToolUseBlock) {
                $blocks[] = ['type' => 'tool_call', 'id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input];
            }
        }

        $stop = (string) $message->stopReason;
        if ($stop === 'refusal' && ! array_filter($blocks, fn ($b) => $b['type'] === 'text')) {
            $blocks[] = ['type' => 'text', 'text' => "I can't help with that one."];
        }

        /** @var list<array<string, mixed>> $raw */
        $raw = json_decode((string) json_encode($message->content), true);

        return new ChatReply(
            blocks: $blocks,
            stopReason: $stop,
            model: (string) $message->model,
            raw: $raw,
            usage: [
                'input' => $message->usage->inputTokens,
                'output' => $message->usage->outputTokens,
                'cache_read' => (int) $message->usage->cacheReadInputTokens,
                'cache_write' => (int) $message->usage->cacheCreationInputTokens,
            ],
        );
    }

    private function client(): Client
    {
        return new Client(
            apiKey: $this->apiKey,
            requestOptions: array_filter([
                'timeout' => (float) $this->timeout,
                'maxRetries' => 2,
                'transporter' => $this->transporter,
            ], fn ($v) => $v !== null),
        );
    }
}
