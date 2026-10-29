<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 *
 * Zero_CacheWarmer: after a deploy or a cache flush, the pages visitors open
 * most are requested once so the full page cache (Varnish or Magento's own)
 * holds them before the first visitor arrives.
 */
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Zero_CacheWarmer', __DIR__);
