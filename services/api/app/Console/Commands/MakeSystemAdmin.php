<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Bootstraps the first System Admin. The flag is intentionally unreachable
 * from the API — the only way to grant it is shell access to the deployment.
 */
class MakeSystemAdmin extends Command
{
    protected $signature = 'magecode:make-system-admin {login : Username or email} {--revoke : Remove the flag instead}';

    protected $description = 'Grant or revoke the platform System Admin flag';

    public function handle(): int
    {
        $login = (string) $this->argument('login');

        $user = User::where('username', $login)->orWhere('email', $login)->first();

        if (! $user) {
            $this->error("No user matches \"{$login}\".");

            return self::FAILURE;
        }

        $granting = ! $this->option('revoke');
        $user->is_system_admin = $granting;
        $user->save();

        $this->info(sprintf(
            '%s is %s a System Admin.',
            $user->username,
            $granting ? 'now' : 'no longer'
        ));

        return self::SUCCESS;
    }
}
