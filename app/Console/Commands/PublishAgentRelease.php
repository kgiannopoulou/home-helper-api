<?php

namespace App\Console\Commands;

use App\Agents\Releases;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * php artisan agent:release 0.6.0 windows-x64 agent/build/home-helper-agent.exe
 *
 * Signs a release with AGENT_RELEASE_SECRET_KEY and offers it to the
 * household's computers of that platform. Nothing is installed until the
 * owner presses Install on the Computers page (agent/docs/updates.md).
 */
class PublishAgentRelease extends Command
{
    protected $signature = 'agent:release
        {release : the version, e.g. 0.6.0}
        {platform : windows-x64, linux-x64, linux-arm64…}
        {file : the built binary}
        {--url= : serve it from this https URL instead of this server}
        {--notes= : what changed, shown on the dashboard}';

    protected $description = 'Sign an agent release and offer it to the household\'s computers';

    public function handle(Releases $releases): int
    {
        try {
            $release = $releases->publish(
                (string) $this->argument('release'),
                (string) $this->argument('platform'),
                (string) $this->argument('file'),
                $this->option('url') ?: null,
                $this->option('notes') ?: null,
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Signed {$release->version} for {$release->platform}: {$release->size} bytes, sha512 ".substr($release->sha512, 0, 16).'…');
        $this->line('Computers on that platform will show "update available"; the owner installs it from the Computers page.');

        return self::SUCCESS;
    }
}
