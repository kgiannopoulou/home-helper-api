<?php

namespace App\Agents;

/**
 * The signatures on agent requests and responses (agent/docs/protocol.md#signatures).
 * The C++ agent builds exactly the same strings (agent/src/net/signing.cpp);
 * both test suites check agent/tests/vectors/signing.json.
 *
 *   request   HH-AGENT-V1 \n METHOD \n /path \n timestamp \n nonce \n agent id \n sha512(body)
 *   response  HH-AGENT-V1-RESPONSE \n status \n request nonce \n sha512(body)
 *
 * Both are HMAC-SHA-512 with the agent's 256-bit secret, in lower-case hex.
 */
final class RequestSignature
{
    public const HEADER_AGENT = 'X-HH-Agent';

    public const HEADER_TIMESTAMP = 'X-HH-Timestamp';

    public const HEADER_NONCE = 'X-HH-Nonce';

    public const HEADER_SIGNATURE = 'X-HH-Signature';

    public static function canonicalRequest(string $method, string $path, string $timestamp, string $nonce, string $agentId, string $body): string
    {
        return implode("\n", ['HH-AGENT-V1', strtoupper($method), $path, $timestamp, $nonce, $agentId, hash('sha512', $body)]);
    }

    public static function canonicalResponse(int $status, string $requestNonce, string $body): string
    {
        return implode("\n", ['HH-AGENT-V1-RESPONSE', (string) $status, $requestNonce, hash('sha512', $body)]);
    }

    public static function signRequest(string $secret, string $method, string $path, string $timestamp, string $nonce, string $agentId, string $body): string
    {
        return hash_hmac('sha512', self::canonicalRequest($method, $path, $timestamp, $nonce, $agentId, $body), $secret);
    }

    public static function signResponse(string $secret, int $status, string $requestNonce, string $body): string
    {
        return hash_hmac('sha512', self::canonicalResponse($status, $requestNonce, $body), $secret);
    }

    public static function verifyRequest(string $secret, string $signature, string $method, string $path, string $timestamp, string $nonce, string $agentId, string $body): bool
    {
        return hash_equals(self::signRequest($secret, $method, $path, $timestamp, $nonce, $agentId, $body), strtolower(trim($signature)));
    }
}
