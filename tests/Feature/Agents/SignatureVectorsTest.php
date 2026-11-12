<?php

use App\Agents\RequestSignature;

/*
 * The same vectors the C++ tests read (agent/tests/net/SigningTests.cpp), so
 * a change on one side that the other doesn't make fails both suites.
 */
function agentVectors(): array
{
    return json_decode(file_get_contents(base_path('agent/tests/vectors/signing.json')), true, flags: JSON_THROW_ON_ERROR);
}

it('builds and signs requests exactly like the agent', function () {
    $v = agentVectors();
    $secret = hex2bin($v['secret_hex']);

    foreach ($v['requests'] as $r) {
        expect(RequestSignature::canonicalRequest($r['method'], $r['path'], $r['timestamp'], $r['nonce'], $r['agent_id'], $r['body']))->toBe($r['canonical'])
            ->and(RequestSignature::signRequest($secret, $r['method'], $r['path'], $r['timestamp'], $r['nonce'], $r['agent_id'], $r['body']))->toBe($r['signature'])
            ->and(RequestSignature::verifyRequest($secret, strtoupper($r['signature']), $r['method'], $r['path'], $r['timestamp'], $r['nonce'], $r['agent_id'], $r['body']))->toBeTrue()
            ->and(RequestSignature::verifyRequest($secret, $r['signature'], $r['method'], $r['path'], $r['timestamp'], $r['nonce'], $r['agent_id'], $r['body'].' '))->toBeFalse();
    }
});

it('signs responses exactly like the agent checks them', function () {
    $v = agentVectors();
    $secret = hex2bin($v['secret_hex']);

    foreach ($v['responses'] as $r) {
        expect(RequestSignature::canonicalResponse($r['status'], $r['request_nonce'], $r['body']))->toBe($r['canonical'])
            ->and(RequestSignature::signResponse($secret, $r['status'], $r['request_nonce'], $r['body']))->toBe($r['signature']);
    }
});

it('verifies the release vector the agent verifies', function () {
    $r = agentVectors()['release'];

    expect(sodium_crypto_sign_verify_detached(hex2bin($r['signature']), $r['manifest'], hex2bin($r['public_key'])))->toBeTrue()
        ->and(hash('sha512', $r['file']))->toBe($r['sha512']);
});
