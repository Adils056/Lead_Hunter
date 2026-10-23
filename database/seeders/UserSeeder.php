<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('slug', 'admin')->first();
        $salesRole = Role::where('slug', 'sales')->first();

        // Admin User
        $admin = User::firstOrCreate(
            ['email' => 'admin@leadpilot.test'],
            [
                'name' => 'Alex Vance (Lead Architect)',
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'is_active' => true,
                'phone' => '+1 (555) 019-2834',
                'email_verified_at' => now(),
            ]
        );
        if ($adminRole) {
            $admin->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        // Sales Rep 1
        $sales1 = User::firstOrCreate(
            ['email' => 'sales@leadpilot.test'],
            [
                'name' => 'Michael Scott',
                'password' => Hash::make('password123'),
                'role' => 'sales',
                'is_active' => true,
                'phone' => '+1 (555) 014-9981',
                'email_verified_at' => now(),
            ]
        );
        if ($salesRole) {
            $sales1->roles()->syncWithoutDetaching([$salesRole->id]);
        }

        // Sales Rep 2
        $sales2 = User::firstOrCreate(
            ['email' => 'sarah@leadpilot.test'],
            [
                'name' => 'Sarah Connor',
                'password' => Hash::make('password123'),
                'role' => 'sales',
                'is_active' => true,
                'phone' => '+44 20 7946 0912',
                'email_verified_at' => now(),
            ]
        );
        if ($salesRole) {
            $sales2->roles()->syncWithoutDetaching([$salesRole->id]);
        }
    }
}
