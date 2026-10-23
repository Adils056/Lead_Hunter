<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(['slug' => 'admin'], [
            'name' => 'Administrator',
            'description' => 'Full access to all platform modules, integrations, campaigns, and settings.',
        ]);

        $salesRole = Role::firstOrCreate(['slug' => 'sales'], [
            'name' => 'Sales Representative',
            'description' => 'Access to assigned leads, CRM pipeline, notes, and permitted campaign outreach.',
        ]);

        $permissions = [
            // Lead permissions
            ['slug' => 'leads.view', 'name' => 'View Leads', 'group' => 'Leads'],
            ['slug' => 'leads.create', 'name' => 'Create Leads', 'group' => 'Leads'],
            ['slug' => 'leads.edit', 'name' => 'Edit Leads', 'group' => 'Leads'],
            ['slug' => 'leads.delete', 'name' => 'Delete Leads', 'group' => 'Leads'],
            ['slug' => 'leads.export', 'name' => 'Export Leads CSV', 'group' => 'Leads'],
            ['slug' => 'leads.import', 'name' => 'Import Leads CSV', 'group' => 'Leads'],
            ['slug' => 'leads.rescore', 'name' => 'Re-score Leads', 'group' => 'Leads'],

            // Company permissions
            ['slug' => 'companies.view', 'name' => 'View Companies', 'group' => 'Companies'],
            ['slug' => 'companies.edit', 'name' => 'Edit Companies', 'group' => 'Companies'],

            // Campaign permissions
            ['slug' => 'campaigns.view', 'name' => 'View Campaigns', 'group' => 'Campaigns'],
            ['slug' => 'campaigns.create', 'name' => 'Create Campaigns', 'group' => 'Campaigns'],
            ['slug' => 'campaigns.approve', 'name' => 'Approve Campaigns', 'group' => 'Campaigns'],
            ['slug' => 'campaigns.stop', 'name' => 'Emergency Stop Campaigns', 'group' => 'Campaigns'],

            // Integrations & Settings
            ['slug' => 'integrations.manage', 'name' => 'Manage Integrations', 'group' => 'Settings'],
            ['slug' => 'settings.manage', 'name' => 'Manage Settings', 'group' => 'Settings'],
            ['slug' => 'users.manage', 'name' => 'Manage Users', 'group' => 'Users'],
        ];

        foreach ($permissions as $perm) {
            $createdPerm = Permission::firstOrCreate(['slug' => $perm['slug']], $perm);
            $adminRole->permissions()->syncWithoutDetaching([$createdPerm->id]);

            // Sales role gets view/create/edit for leads & companies
            if (in_array($perm['slug'], ['leads.view', 'leads.create', 'leads.edit', 'companies.view', 'campaigns.view'])) {
                $salesRole->permissions()->syncWithoutDetaching([$createdPerm->id]);
            }
        }
    }
}
