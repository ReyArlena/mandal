<?php

namespace App\Policies;

use App\Models\User;

/**
 * Every signed-in user may manage every administrator, except that nobody can
 * delete their own account, which also means one account always remains.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, User $administrator): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, User $administrator): bool
    {
        return true;
    }

    public function delete(User $user, User $administrator): bool
    {
        return ! $user->is($administrator);
    }

    public function restore(User $user, User $administrator): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $administrator): bool
    {
        return false;
    }
}
