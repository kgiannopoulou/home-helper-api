<?php

namespace Tests\Support;

use App\Agents\RequestSignature;
use App\Models\Agent;
use App\Models\Household;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * Talks to /api/agent/v1 the way the C++ agent does: signs every request
 * with the agent's secret and checks the signature on every response.
 */
class AgentClient
{
    public string $lastNonce = '';

    public function __construct(private TestCase $test, public Agent $agent) {}

    public static function enrolled(TestCase $test, Household $household, array $attributes = []): self
    {
        $agent = $household->agents()->create($attributes + [
            'name' => 'Mini PC',
            'hostname' => 'MINIPC',
            'platform' => 'windows-x64',
            'version' => '0.5.0',
            'secret' => bin2hex(random_bytes(32)),
            'last_seen_at' => now(),
        ]);

        return new self($test, $agent);
    }

    /**
     * @param  array<string, mixed>|string|null  $body  an array is sent as JSON
     * @param  array<string, string>  $override  header => value, to forge a request
     */
    public function send(string $method, string $endpoint, array|string|null $body = null, array $override = [], ?int $timestamp = null, ?string $nonce = null): TestResponse
    {
        $uri = '/api/agent/v1/'.$endpoint;
        $content = is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($body ?? '');
        $timestamp = (string) ($timestamp ?? time());
        $this->lastNonce = $nonce ?? bin2hex(random_bytes(16));

        $headers = [
            RequestSignature::HEADER_AGENT => $this->agent->id,
            RequestSignature::HEADER_TIMESTAMP => $timestamp,
            RequestSignature::HEADER_NONCE => $this->lastNonce,
            RequestSignature::HEADER_SIGNATURE => RequestSignature::signRequest($this->agent->secretBytes(), $method, $uri, $timestamp, $this->lastNonce, $this->agent->id, $content),
        ];
        $headers = array_merge($headers, $override);

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->test->call($method, $uri, [], [], [], $server, $content);
    }

    /** @param array<string, mixed> $body */
    public function post(string $endpoint, array $body = []): TestResponse
    {
        return $this->assertSigned($this->send('POST', $endpoint, $body));
    }

    public function get(string $endpoint): TestResponse
    {
        return $this->assertSigned($this->send('GET', $endpoint));
    }

    /** What the agent checks before it acts on a response */
    public function assertSigned(TestResponse $response): TestResponse
    {
        $expected = RequestSignature::signResponse($this->agent->secretBytes(), $response->getStatusCode(), $this->lastNonce, (string) $response->getContent());
        Assert::assertSame($expected, $response->headers->get(RequestSignature::HEADER_SIGNATURE), 'The response is not signed for this request.');

        return $response;
    }
}
