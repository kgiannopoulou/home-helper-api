<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stands in for the network under the real Anthropic SDK: records each HTTP
 * request it would have sent and answers with canned JSON. So a test runs
 * ClaudeChatModel → SDK → "HTTP" → SDK parsing for real, just offline.
 */
class FakeAnthropicHttp implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    /**
     * @param  list<array{0: int, 1: array<string, mixed>}>  $responses  [status, json body]
     */
    public function __construct(private array $responses) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;
        [$status, $body] = array_shift($this->responses) ?? [500, ['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'script ran out']]];

        return new Response($status, ['Content-Type' => 'application/json', 'request-id' => 'req_test'], (string) json_encode($body));
    }

    /** @return array<string, mixed> the JSON body of the n-th request */
    public function body(int $n = 0): array
    {
        return json_decode((string) $this->sent[$n]->getBody(), true);
    }
}
