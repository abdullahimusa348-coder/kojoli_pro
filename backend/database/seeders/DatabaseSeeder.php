<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * No users are seeded. Create the first admin with `php artisan nadabo:create-admin`.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
