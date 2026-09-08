<?php

namespace hexa_package_wptoolkit\Contracts;

/** Optional application-owned saved-site inventory for the administrator dashboard. */
interface SiteCatalog
{
    /**
     * @return list<array{id: int, name: string, url: string, hosting_account_id: int|null, install_id: int|null, status: string, last_error: string|null}>
     */
    public function sites(): array;

    /**
     * Resolve an existing saved site or throw the application's not-found exception.
     *
     * @return array{id: int, name: string, url: string, hosting_account_id: int|null, install_id: int|null}
     */
    public function findOrFail(int $id): array;
}
