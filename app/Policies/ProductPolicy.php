<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Same rule as PostPolicy: any CMS role reads and edits, only administrators delete. Publishing is decided by
 * ProductOperations (only administrators), not here, so editors can still save drafts and stage proposals.
 * Discovered by Laravel's App\Models\X → App\Policies\XPolicy convention.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, config('cms.roles', []), true);
    }

    public function view(User $user, Product $product): bool
    {
        return in_array($user->role, config('cms.roles', []), true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, config('cms.roles', []), true);
    }

    public function update(User $user, Product $product): bool
    {
        return in_array($user->role, config('cms.roles', []), true);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->isAdmin();
    }
}
