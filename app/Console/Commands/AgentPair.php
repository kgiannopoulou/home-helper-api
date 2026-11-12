<?php

namespace App\Console\Commands;

use App\Agents\Enrollment;
use App\Enums\HouseholdRole;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * php artisan agent:pair — a pairing code without the dashboard, for a
 * headless server or a script (agent/scripts/e2e.sh). Same rules as the
 * Computers page: the person must own a household, the code works once
 * within 10 minutes.
 */
class AgentPair extends Command
{
    protected $signature = 'agent:pair
        {--email=demo@homehelper.test : the household owner}
        {--household= : the household id (default: the owner\'s first)}
        {--name= : a name for the computer}';

    protected $description = 'Create a one-time code to enroll a computer running home-helper-agent';

    public function handle(Enrollment $enrollment): int
    {
        $user = User::query()->where('email', $this->option('email'))->first();
        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $household = $this->option('household')
            ? $user->households()->whereKey($this->option('household'))->first()
            : $user->households()->wherePivot('role', HouseholdRole::Owner->value)->orderBy('households.created_at')->first();
        if (! $household || $user->roleIn($household) !== HouseholdRole::Owner) {
            $this->error('That person does not own that household.');

            return self::FAILURE;
        }

        [$code, $row] = $enrollment->createCode($household, $user, $this->option('name'));

        $this->line($code);
        $this->getOutput()->getErrorStyle()->writeln("For \"{$household->name}\", valid until {$row->expires_at->toDateTimeString()} UTC, once.");

        return self::SUCCESS;
    }
}
