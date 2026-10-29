<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Model;

use Magento\Store\Api\Data\StoreInterface;

/**
 * Other addresses that serve a store view's pages under the same paths (another storefront in front of the same
 * store, with its own cache). Registered in di.xml (Warmer's `targets`); each address gets the same paths as the
 * store's own, requested there directly (not through Varnish), after the store's own.
 */
interface ExtraTargetInterface
{
    /**
     * @return string[] base URLs ending in "/"; [] when the store view has none
     */
    public function bases(StoreInterface $store): array;
}
