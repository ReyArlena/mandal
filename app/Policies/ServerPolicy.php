<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

/**
 * Every signed-in user may manage every server for now. Per-server or
 * read-only access can be introduced here later without touching pages.
 */
class ServerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Server $server): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Server $server): bool
    {
        return true;
    }

    public function delete(User $user, Server $server): bool
    {
        return true;
    }

    public function restore(User $user, Server $server): bool
    {
        return false;
    }

    public function forceDelete(User $user, Server $server): bool
    {
        return false;
    }
}
