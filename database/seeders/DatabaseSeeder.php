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
            UserSeeder::class,
            LeadSourceSeeder::class,
            EmailTemplateSeeder::class,
            IntegrationSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
