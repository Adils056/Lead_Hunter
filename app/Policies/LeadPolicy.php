<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Lead $lead): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->is_active && ($lead->assigned_user_id === $user->id || $lead->assigned_user_id === null);
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Lead $lead): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->is_active && ($lead->assigned_user_id === $user->id || $lead->assigned_user_id === null);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->isAdmin();
    }

    public function export(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('leads.export');
    }

    public function import(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('leads.import');
    }

    public function rescore(User $user, Lead $lead): bool
    {
        return $user->is_active;
    }
}
