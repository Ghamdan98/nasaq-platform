<?php

namespace App\Policies;

use App\Models\User;

class SystemSettingPolicy
{
    public function manage(User $user): bool
    {
        return $user->status === 'active' && $user->role === 'developer' && $user->hasExplicitPermission('manage-system-settings');
    }
}
