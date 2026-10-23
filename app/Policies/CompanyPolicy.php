<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Company $company): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // Sales reps can view companies assigned to them or unassigned
        return $user->is_active && ($company->assigned_user_id === $user->id || $company->assigned_user_id === null);
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Company $company): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->is_active && ($company->assigned_user_id === $user->id || $company->assigned_user_id === null);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->isAdmin();
    }

    public function export(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('leads.export');
    }
}
