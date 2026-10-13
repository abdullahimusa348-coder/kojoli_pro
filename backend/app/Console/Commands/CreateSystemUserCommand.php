<?php

namespace App\Console\Commands;

use App\Models\SystemUser;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Creates a staff (system user) account, or gives an existing one a role.
 * The password is typed in interactively and never stored in code, seeders or .env.
 * Staff accounts are separate from customer accounts.
 */
class CreateSystemUserCommand extends Command
{
    protected $signature = 'nadabo:create-system-user
        {email : Email address for the staff account}
        {--name=Administrator : Display name}
        {--role= : super-admin, manager, support, finance or viewer}';

    protected $description = 'Create a staff (admin area) account or assign a role to an existing one';

    public function handle(): int
    {
        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $email = mb_strtolower(trim((string) $this->argument('email')));
        $role = SystemRole::tryFrom((string) ($this->option('role') ?: $this->choice('Role', SystemRole::values())));

        if ($role === null) {
            $this->error('Unknown role. Use one of: '.implode(', ', SystemRole::values()));

            return self::FAILURE;
        }

        $staff = SystemUser::where('email', $email)->first();

        if ($staff === null) {
            $password = (string) $this->secret('Password for '.$email);
            $confirm = (string) $this->secret('Confirm password');

            $validator = Validator::make(
                ['email' => $email, 'password' => $password, 'password_confirmation' => $confirm],
                [
                    'email' => ['required', 'email', 'max:255', Rule::unique(SystemUser::class, 'email')],
                    'password' => ['required', 'confirmed', Password::defaults()],
                ],
            );

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }

            $staff = new SystemUser(['name' => $this->option('name'), 'email' => $email, 'password' => $password]);
            $staff->status = UserStatus::Active;
            $staff->save();

            $this->info("Created staff account {$email}.");
        }

        $staff->assignRole($role->value);

        $this->info("Granted {$role->label()} to {$email}. Sign in at /admin/login.");

        return self::SUCCESS;
    }
}
