<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Who handles orders (PLAN.md E3.6, track 3B; decision 6.2, no shop_manager role): every CMS role sees orders, keeps
 * internal notes, resends the customer email and marks orders delivered; only administrators move money or stock
 * by hand (confirm, fail and refund payments, cancel orders). App\Services\Shop\OrderOperations and
 * PaymentOperations authorize through these abilities with Gate::forUser($actor). Discovered by Laravel's
 * App\Models\X → App\Policies\XPolicy convention.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isCmsUser($user);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->isCmsUser($user);
    }

    public function fulfill(User $user, Order $order): bool
    {
        return $this->isCmsUser($user);
    }

    public function note(User $user, Order $order): bool
    {
        return $this->isCmsUser($user);
    }

    public function resend(User $user, Order $order): bool
    {
        return $this->isCmsUser($user);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }

    public function confirmPayment(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }

    public function failPayment(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }

    public function refund(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }

    private function isCmsUser(User $user): bool
    {
        return in_array($user->role, config('cms.roles', []), true);
    }
}
