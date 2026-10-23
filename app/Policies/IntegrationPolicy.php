<?php

namespace App\Policies;

use App\Models\Integration;
use App\Models\User;

class IntegrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Integration $integration): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Integration $integration): bool
    {
        return $user->isAdmin();
    }

    public function test(User $user, Integration $integration): bool
    {
        return $user->isAdmin();
    }
}
