<?php

namespace hexa_package_wptoolkit\Contracts;

/** Optional app-owned account grants; without a binding WP Toolkit remains admin-only. */
interface AccountAccessPolicy
{
    public function canAccess(mixed $user, int $accountId): bool;
}
