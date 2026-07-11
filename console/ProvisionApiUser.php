<?php namespace Renick\TailorCompanion\Console;

use Backend\Models\User;
use Illuminate\Console\Command;

/**
 * ProvisionApiUser creates or resets a dedicated backend user that has
 * companion-API access — handy for an automated pairing account, e.g. the
 * demo account App Store review uses.
 *
 * Idempotent: re-running resets the password and re-asserts activation and the
 * API-access permission, so it doubles as a "reset the review account" step.
 *
 *   php artisan tailor-companion:api-user --login=applereview --password=... [--superuser]
 */
class ProvisionApiUser extends Command
{
    protected $signature = 'tailor-companion:api-user
        {--login=applereview : Backend login for the API user}
        {--password= : Password to set (or TAILOR_API_USER_PASSWORD env)}
        {--email= : Email (defaults to <login>@example.com)}
        {--name=App Review : Display first name}
        {--superuser : Grant superuser (guarantees full content visibility)}';

    protected $description = 'Create or reset a backend user with companion API access (e.g. an App Store review demo account).';

    public function handle(): int
    {
        $login = trim((string) $this->option('login'));
        if ($login === '') {
            $this->error('A --login is required.');
            return 1;
        }

        $password = (string) ($this->option('password') ?: env('TAILOR_API_USER_PASSWORD', ''));
        if ($password === '') {
            $this->error('A password is required — pass --password="..." or set TAILOR_API_USER_PASSWORD.');
            return 1;
        }

        $email = trim((string) $this->option('email')) ?: $login . '@example.com';

        $user = User::where('login', $login)->first();
        $existed = (bool) $user;
        $user ??= new User;

        $user->login = $login;
        $user->email = $email;
        $user->first_name = (string) $this->option('name');
        $user->last_name = 'API';
        $user->password = $password;
        $user->password_confirmation = $password;
        $user->is_activated = true;

        if ($this->option('superuser')) {
            $user->is_superuser = true;
        }

        // Grant the companion API-access permission directly on the user, so it
        // works without depending on a role being present.
        $permissions = (array) $user->permissions;
        $permissions['renick.tailorcompanion.access_api'] = 1;
        $user->permissions = $permissions;

        $user->save();

        $this->info(sprintf(
            '%s API user "%s" (%s)%s.',
            $existed ? 'Reset' : 'Created',
            $login,
            $email,
            $this->option('superuser') ? ' [superuser]' : ''
        ));

        return 0;
    }
}
