<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates (or promotes) a staff account. The password is always typed in
 * interactively and never stored in code, seeders or .env.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'nadabo:create-admin
        {email : Email address for the admin account}
        {--name=Administrator : Display name}
        {--super : Grant the super-admin role instead of admin}';

    protected $description = 'Create an admin account, or grant admin access to an existing user';

    public function handle(): int
    {
        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $email = mb_strtolower(trim($this->argument('email')));
        $role = $this->option('super') ? RolesAndPermissionsSeeder::SUPER_ADMIN : RolesAndPermissionsSeeder::ADMIN;

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $password = (string) $this->secret('Password for '.$email);
            $confirm = (string) $this->secret('Confirm password');

            $validator = Validator::make(
                ['email' => $email, 'password' => $password, 'password_confirmation' => $confirm],
                ['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'confirmed', Password::defaults()]],
            );

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }

            $user = new User(['name' => $this->option('name'), 'email' => $email, 'password' => $password]);
            $user->user_type = UserType::Subscriber;
            $user->status = UserStatus::Active;
            $user->email_verified_at = now();
            $user->save();

            $this->info("Created {$email}.");
        }

        $user->assignRole($role);

        $this->info("Granted {$role} to {$email}. Sign in at /admin/login.");

        return self::SUCCESS;
    }
}
