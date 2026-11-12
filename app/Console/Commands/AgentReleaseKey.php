<?php

namespace App\Console\Commands;

use App\Agents\Releases;
use Illuminate\Console\Command;

/**
 * php artisan agent:release-key — a new Ed25519 key pair for signing agent
 * releases. The secret goes in the server's .env (better: only on the machine
 * that builds releases); the public key goes in every agent's agent.json,
 * which is what makes the server unable to push anything unsigned.
 */
class AgentReleaseKey extends Command
{
    protected $signature = 'agent:release-key';

    protected $description = 'Make an Ed25519 key pair for signing agent releases';

    public function handle(): int
    {
        $pair = Releases::generateKeyPair();

        $this->line('In the .env of whatever signs releases (keep it secret):');
        $this->line('  AGENT_RELEASE_SECRET_KEY='.$pair['secret']);
        $this->line('  AGENT_RELEASE_PUBLIC_KEY='.$pair['public']);
        $this->newLine();
        $this->line('In every computer\'s agent.json:');
        $this->line('  "updates": { "public_key": "'.$pair['public'].'" }');

        return self::SUCCESS;
    }
}
