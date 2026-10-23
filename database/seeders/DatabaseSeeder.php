<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            IcpProfileSeeder::class,
            RoleAndPermissionSeeder::class,
            LeadSourceSeeder::class,
            EmailTemplateSeeder::class,
            IntegrationSeeder::class,
        ]);
        // Demo users (known password) and demo companies never reach a live server by accident:
        // only when APP_ENV=local/testing or LEADPILOT_SEED_DEMO=true. On a fresh live install the first
        // account is created through /register (it becomes admin).
        if (app()->environment('local', 'testing') || filter_var(env('LEADPILOT_SEED_DEMO', false), FILTER_VALIDATE_BOOL)) {
            $this->call([UserSeeder::class, DemoDataSeeder::class]);
        }
    }
}
