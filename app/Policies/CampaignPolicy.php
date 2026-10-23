<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Campaign $campaign): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $campaign->created_by === $user->id && $campaign->status === 'draft';
    }

    public function approve(User $user, Campaign $campaign): bool
    {
        // Only administrators can approve campaigns
        return $user->isAdmin();
    }

    public function emergencyStop(User $user, Campaign $campaign): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->isAdmin();
    }
}
