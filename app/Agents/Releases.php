<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRelease;
use App\Models\User;
use App\Policies\AgentPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Signed agent releases and who gets offered what (agent/docs/updates.md).
 *
 * The server is only a messenger here: it signs releases with a key whose
 * public half every agent has in its own config, and the agent verifies
 * before it downloads. Even a compromised server can't make an agent run a
 * binary the release key didn't sign. Nothing is installed unless the
 * household owner presses Install.
 */
class Releases
{
    public const DISK = 'local';

    public const DIRECTORY = 'agent-releases';

    public static function manifest(string $version, string $platform, string $sha512, int $size): string
    {
        return implode("\n", ['HH-AGENT-RELEASE-V1', $version, $platform, $sha512, (string) $size]);
    }

    /** @return array{public: string, secret: string} hex */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => bin2hex(sodium_crypto_sign_publickey($pair)),
            'secret' => bin2hex(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    /**
     * Signs and records a release. The file is copied to storage unless an
     * https URL is given for it.
     */
    public function publish(string $version, string $platform, string $file, ?string $url = null, ?string $notes = null): AgentRelease
    {
        $secret = config('homehelper.agents.release_secret_key');
        if (! $secret || strlen((string) $secret) !== 128 || ! ctype_xdigit((string) $secret)) {
            throw new RuntimeException('Set AGENT_RELEASE_SECRET_KEY (php artisan agent:release-key) to sign releases.');
        }
        if (! preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version) || ! preg_match('/^[a-z0-9]+-[a-z0-9]+$/', $platform)) {
            throw new RuntimeException('The version must be like 0.6.0 and the platform like windows-x64.');
        }
        if (! is_file($file)) {
            throw new RuntimeException("No file at {$file}.");
        }
        if ($url !== null && ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('A release URL must be https://.');
        }

        $sha512 = hash_file('sha512', $file);
        $size = filesize($file);
        $secretKey = hex2bin((string) $secret);
        if ($sha512 === false || $size === false || $size === 0 || $secretKey === false || $secretKey === '') {
            throw new RuntimeException("Can't read {$file}, or the key is not hex.");
        }
        $manifest = self::manifest($version, $platform, $sha512, $size);
        $signature = sodium_crypto_sign_detached($manifest, $secretKey);

        // The signature must check out with the public key agents are configured with
        $public = hex2bin((string) config('homehelper.agents.release_public_key'));
        if ($public !== false && $public !== '' && ! sodium_crypto_sign_verify_detached($signature, $manifest, $public)) {
            throw new RuntimeException('AGENT_RELEASE_SECRET_KEY and AGENT_RELEASE_PUBLIC_KEY are not a pair.');
        }
        $signature = bin2hex($signature);

        $path = null;
        if ($url === null) {
            $path = self::DIRECTORY."/{$version}/{$platform}/".basename($file);
            Storage::disk(self::DISK)->put($path, (string) file_get_contents($file));
        }

        return AgentRelease::updateOrCreate(
            ['version' => $version, 'platform' => $platform],
            ['path' => $path, 'url' => $url, 'sha512' => $sha512, 'size' => $size, 'signature' => $signature, 'notes' => $notes, 'published_at' => CarbonImmutable::now()],
        );
    }

    /** The newest release for this computer's platform, if it is newer than what it runs */
    public function newestFor(Agent $agent): ?AgentRelease
    {
        return AgentRelease::query()
            ->where('platform', $agent->platform)
            ->get()
            ->filter(fn (AgentRelease $r) => version_compare($r->version, $agent->version, '>'))
            ->sort(fn (AgentRelease $a, AgentRelease $b) => version_compare($b->version, $a->version))
            ->first();
    }

    /**
     * For the heartbeat reply
     *
     * @return array<string, mixed>|null
     */
    public function offerFor(Agent $agent): ?array
    {
        $release = $this->newestFor($agent);
        if (! $release) {
            return null;
        }

        return [
            'version' => $release->version,
            'platform' => $release->platform,
            // A path is downloaded with a signed request (GET releases/{id}/download)
            'url' => $release->url ?? "releases/{$release->id}/download",
            'sha512' => $release->sha512,
            'size' => $release->size,
            'signature' => $release->signature,
            'install' => $agent->update_requested_version === $release->version,
        ];
    }

    /** The owner pressed Install: the next heartbeat's offer says install */
    public function requestInstall(Agent $agent, User $user): AgentRelease
    {
        if (! (new AgentPolicy)->manage($user, $agent)) {
            throw ValidationException::withMessages(['agent' => 'Only the household owner can install updates.']);
        }
        $release = $this->newestFor($agent);
        if (! $release) {
            throw ValidationException::withMessages(['agent' => 'This computer is up to date.']);
        }
        $agent->forceFill(['update_requested_version' => $release->version, 'update_requested_by' => $user->id])->save();

        return $release;
    }
}
