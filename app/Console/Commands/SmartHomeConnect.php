<?php

namespace App\Console\Commands;

use App\Enums\HouseholdRole;
use App\Models\SmartHomeConnection;
use App\Models\User;
use App\SmartHome\Connections;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * php artisan smart-home:connect — connect a household to Home Assistant
 * without the dashboard (the end-to-end script, a headless install). Same
 * checks as the Smart home page. Prints the webhook token for Home
 * Assistant's secrets.yaml on stdout, everything else on stderr.
 */
class SmartHomeConnect extends Command
{
    protected $signature = 'smart-home:connect
        {url : Home Assistant\'s address, e.g. http://192.168.1.20:8123}
        {--token= : a long-lived access token (or HOME_ASSISTANT_TOKEN in the environment)}
        {--email=demo@homehelper.test : the household owner}
        {--household= : the household id (default: the owner\'s first)}';

    protected $description = 'Connect a household to its Home Assistant';

    public function handle(Connections $connections): int
    {
        $user = User::query()->where('email', $this->option('email'))->first();
        $household = $user && $this->option('household')
            ? $user->households()->whereKey($this->option('household'))->first()
            : $user?->households()->wherePivot('role', HouseholdRole::Owner->value)->orderBy('households.created_at')->first();
        if (! $user || ! $household) {
            $this->error('That person does not own that household.');

            return self::FAILURE;
        }

        $token = (string) ($this->option('token') ?: getenv('HOME_ASSISTANT_TOKEN') ?: '');
        try {
            [$connection, $secret] = $connections->connect($household, $user, (string) $this->argument('url'), $token);
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $err = $this->getOutput()->getErrorStyle();
        $err->writeln("Connected \"{$household->name}\" to {$connection->location_name} (Home Assistant {$connection->ha_version}).");
        $err->writeln('Webhook token for secrets.yaml (home_helper_events_auth: "Bearer <token>"):');
        $this->line(SmartHomeConnection::webhookToken($connection->id, $secret));

        return self::SUCCESS;
    }
}
