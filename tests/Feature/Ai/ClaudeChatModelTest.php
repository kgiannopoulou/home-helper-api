<?php

use App\Ai\AiUnavailable;
use App\Ai\ChatRequest;
use App\Ai\Providers\ClaudeChatModel;
use App\Models\AiMessage;
use Tests\Support\FakeAnthropicHttp;

/*
 * Claude through the real Anthropic SDK, with a fake HTTP client underneath:
 * what goes on the wire, and how the answer comes back.
 */

function claude(?FakeAnthropicHttp $http = null, ?string $key = 'sk-ant-test'): ClaudeChatModel
{
    return new ClaudeChatModel(apiKey: $key, model: 'claude-opus-5-5', effort: 'medium', maxTokens: 16000, transporter: $http);
}

/** An unsaved transcript entry */
function entry(string $role, array $content): AiMessage
{
    return new AiMessage(['role' => $role, 'content' => $content]);
}

function chatRequest(array $transcript): ChatRequest
{
    return new ChatRequest(
        instructions: 'You are Home Helper.',
        context: 'Today is Tuesday 10 November 2026.',
        transcript: $transcript,
        tools: [['name' => 'get_shopping_list', 'description' => 'The list.', 'input_schema' => ['type' => 'object', 'properties' => new stdClass]]],
    );
}

/** A Messages API response body */
function apiMessage(array $content, string $stop = 'end_turn'): array
{
    return [
        'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
        'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null,
        'usage' => ['input_tokens' => 1200, 'output_tokens' => 80, 'cache_read_input_tokens' => 1000, 'cache_creation_input_tokens' => 0],
    ];
}

test('the request: model, effort, refusal fallback, cached instructions before the daily context, tools', function () {
    $http = new FakeAnthropicHttp([[200, apiMessage([['type' => 'text', 'text' => 'Milk.']])]]);
    claude($http)->reply(chatRequest([entry('user', ['text' => 'What do I need to buy?'])]));

    $body = $http->body();
    expect($body['model'])->toBe('claude-opus-5-5')
        ->and($body['max_tokens'])->toBe(16000)
        ->and($body['output_config'])->toBe(['effort' => 'medium'])
        ->and($body['fallbacks'])->toBe('default')
        ->and($body)->not->toHaveKey('thinking') // adaptive by default on this model
        ->and($body['system'][0])->toMatchArray(['text' => 'You are Home Helper.', 'cache_control' => ['type' => 'ephemeral']])
        ->and($body['system'][1])->toBe(['type' => 'text', 'text' => 'Today is Tuesday 10 November 2026.'])
        ->and($body['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['tools'][0])->toMatchArray(['name' => 'get_shopping_list', 'input_schema' => ['type' => 'object', 'properties' => []]])
        ->and($body['messages'])->toBe([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'What do I need to buy?']]]]);

    expect($http->sent[0]->getHeaderLine('anthropic-beta'))->toContain(ClaudeChatModel::FALLBACK_BETA)
        ->and($http->sent[0]->getHeaderLine('authorization'))->toBe('Bearer sk-ant-test');
});

test('a reply with thinking and a tool call becomes neutral blocks, and keeps the raw blocks', function () {
    $http = new FakeAnthropicHttp([[200, apiMessage([
        ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-abc'],
        ['type' => 'text', 'text' => 'Let me look.'],
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_shopping_list', 'input' => new stdClass],
    ], 'tool_use')]]);

    $reply = claude($http)->reply(chatRequest([entry('user', ['text' => 'What do I need to buy?'])]));

    expect($reply->stopReason)->toBe('tool_use')
        ->and($reply->blocks)->toBe([
            ['type' => 'text', 'text' => 'Let me look.'],
            ['type' => 'tool_call', 'id' => 'toolu_1', 'name' => 'get_shopping_list', 'input' => []],
        ])
        ->and($reply->raw[0])->toMatchArray(['type' => 'thinking', 'signature' => 'sig-abc'])
        ->and($reply->usage)->toBe(['input' => 1200, 'output' => 80, 'cache_read' => 1000, 'cache_write' => 0]);
});

test('history goes back unchanged: thinking signatures, tool ids, tool results as JSON, events from Home Helper', function () {
    $raw = [
        ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-abc'],
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_shopping_list', 'input' => []],
    ];
    $http = new FakeAnthropicHttp([[200, apiMessage([['type' => 'text', 'text' => 'Milk and bread.']])]]);

    claude($http)->reply(chatRequest([
        entry('user', ['text' => 'What do I need to buy?']),
        entry('assistant', ['provider' => 'claude', 'raw' => $raw, 'blocks' => [['type' => 'tool_call', 'id' => 'toolu_1', 'name' => 'get_shopping_list', 'input' => []]]]),
        entry('tool_results', ['results' => [['tool_call_id' => 'toolu_1', 'name' => 'get_shopping_list', 'content' => ['items' => ['Milk']], 'is_error' => false]]]),
        entry('assistant', ['provider' => 'claude', 'raw' => [['type' => 'text', 'text' => 'Milk.']], 'blocks' => [['type' => 'text', 'text' => 'Milk.']]]),
        entry('event', ['text' => 'The person confirmed "Add bread". It is done.']),
        entry('user', ['text' => 'Thanks. Anything else?']),
    ]));

    $messages = $http->body()['messages'];
    expect($messages[1]['content'][0])->toMatchArray(['type' => 'thinking', 'signature' => 'sig-abc', 'thinking' => ''])
        ->and($messages[1]['content'][1])->toMatchArray(['type' => 'tool_use', 'id' => 'toolu_1', 'input' => []])
        ->and($messages[2]['content'][0])->toBe(['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"items":["Milk"]}', 'is_error' => false])
        ->and($messages[4])->toBe(['role' => 'user', 'content' => [['type' => 'text', 'text' => '[Home Helper] The person confirmed "Add bread". It is done.']]])
        ->and((string) $http->sent[0]->getBody())->toContain('"input":{}'); // an empty input stays an object on the wire
});

test('a turn written by another provider is replayed from its neutral blocks', function () {
    $model = claude();
    $turns = $model->messages([
        entry('user', ['text' => 'hi']),
        entry('assistant', ['provider' => 'offline', 'raw' => [], 'blocks' => [['type' => 'tool_call', 'id' => 'offline_1', 'name' => 'get_today_plan', 'input' => []]]]),
    ]);

    expect($turns[1]['content'][0])->toMatchArray(['type' => 'tool_use', 'id' => 'offline_1', 'name' => 'get_today_plan'])
        ->and($turns[1]['content'][0]['input'])->toBeInstanceOf(stdClass::class);
});

test('a refusal says so instead of an empty answer', function () {
    $http = new FakeAnthropicHttp([[200, apiMessage([], 'refusal') + ['stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => null]]]]);

    $reply = claude($http)->reply(chatRequest([entry('user', ['text' => '…'])]));

    expect($reply->stopReason)->toBe('refusal')->and($reply->blocks[0]['text'])->toBe("I can't help with that one.");
});

test('no API key: unavailable, and not worth retrying', function () {
    expect(fn () => claude(key: null)->reply(chatRequest([entry('user', ['text' => 'hi'])])))
        ->toThrow(fn (AiUnavailable $e) => expect($e->retryable)->toBeFalse()->and($e->getMessage())->toContain('ANTHROPIC_API_KEY'));
});

test('a refused key and a bad request are not retryable; neither leaks the key', function (int $status, string $type, string $message) {
    $http = new FakeAnthropicHttp([[$status, ['type' => 'error', 'error' => ['type' => $type, 'message' => 'nope']]]]);

    expect(fn () => claude($http)->reply(chatRequest([entry('user', ['text' => 'hi'])])))
        ->toThrow(fn (AiUnavailable $e) => expect($e->retryable)->toBeFalse()
            ->and($e->getMessage())->toContain($message)->not->toContain('sk-ant-test'));
    expect($http->sent)->toHaveCount(1);
})->with([
    'bad key' => [401, 'authentication_error', 'API key was refused'],
    'bad request' => [400, 'invalid_request_error', 'rejected the request'],
]);
