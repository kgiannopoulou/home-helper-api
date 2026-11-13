<?php

namespace App\SmartHome;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Home Assistant's REST API (https://developers.home-assistant.io/docs/api/rest/),
 * the few endpoints Home Helper needs. Every failure becomes a
 * HomeAssistantError with a kind, so callers decide on the kind, not on
 * HTTP codes and exception classes.
 *
 * Short timeouts on purpose: Home Assistant is on the local network, and a
 * dashboard button that hangs for 30 seconds is worse than one that says
 * "not answering" after 5.
 */
class HomeAssistant
{
    public function __construct(
        private string $baseUrl,
        private string $token,
        private int $timeoutSeconds = 5,
    ) {}

    /**
     * GET /api/config: the location name, version, time zone. Also how a
     * connection is checked: it needs a valid token.
     *
     * @return array{location_name: string, version: string, time_zone: ?string}
     */
    public function config(): array
    {
        $body = $this->json($this->send('get', '/api/config'));
        if (! isset($body['version'])) {
            throw new HomeAssistantError('not_found', 'That address answers, but not like Home Assistant.');
        }

        return [
            'location_name' => (string) ($body['location_name'] ?? 'Home'),
            'version' => (string) $body['version'],
            'time_zone' => isset($body['time_zone']) ? (string) $body['time_zone'] : null,
        ];
    }

    /**
     * GET /api/states: every entity and its current state
     *
     * @return list<array{entity_id: string, state: string, attributes: array<string, mixed>, last_changed: string, last_updated?: string}>
     */
    public function states(): array
    {
        $body = $this->json($this->send('get', '/api/states'));

        return array_values(array_filter($body, fn ($s) => is_array($s) && isset($s['entity_id'], $s['state'])));
    }

    /**
     * GET /api/states/<entity_id>
     *
     * @return array{entity_id: string, state: string, attributes: array<string, mixed>, last_changed: string}
     */
    public function state(string $entityId): array
    {
        return $this->json($this->send('get', '/api/states/'.rawurlencode($entityId)));
    }

    /**
     * POST /api/services/<domain>/<service>, e.g. light.turn_on. Returns the
     * states that changed while the call ran (often the entity itself).
     *
     * @param  array<string, mixed>  $data  service data, including entity_id
     * @return list<array<string, mixed>>
     */
    public function callService(string $domain, string $service, array $data): array
    {
        $body = $this->json($this->send('post', "/api/services/{$domain}/{$service}", $data));

        return array_values(array_filter($body, 'is_array'));
    }

    /** POST /api/template: render a Jinja template inside Home Assistant (areas live there) */
    public function render(string $template): string
    {
        return $this->send('post', '/api/template', ['template' => $template])->body();
    }

    /** @param  array<string, mixed>|null  $data */
    private function send(string $method, string $path, ?array $data = null): Response
    {
        try {
            $response = $this->request()->send(strtoupper($method), $this->baseUrl.$path, $data === null ? [] : ['json' => $data]);
        } catch (ConnectionException $e) {
            throw new HomeAssistantError('unreachable', 'No answer from '.$this->baseUrl.'.', $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $detail = mb_substr(trim(strip_tags($response->body())), 0, 200);
        throw match (true) {
            in_array($response->status(), [401, 403], true) => new HomeAssistantError('unauthorized', 'The token was refused.'),
            $response->status() === 404 => new HomeAssistantError('not_found', "Not found: {$path}"),
            $response->status() === 400 => new HomeAssistantError('rejected', $detail ?: 'Bad request'),
            // A reverse proxy in front of a Pi that's off
            in_array($response->status(), [502, 503, 504], true) => new HomeAssistantError('unreachable', "HTTP {$response->status()} from {$this->baseUrl}."),
            default => new HomeAssistantError('error', "HTTP {$response->status()}".($detail ? ": {$detail}" : '')),
        };
    }

    /** @return array<mixed> */
    private function json(Response $response): array
    {
        $body = $response->json();
        if (! is_array($body)) {
            throw new HomeAssistantError('error', 'Home Assistant sent something that isn\'t JSON.');
        }

        return $body;
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->connectTimeout(min(3, $this->timeoutSeconds))
            ->timeout($this->timeoutSeconds)
            // Never follow a redirect with the token to wherever it points
            ->withoutRedirecting()
            ->withUserAgent('HomeHelper/1 (+docs/smart-home.md)');
    }
}
