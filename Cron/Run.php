<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Cron;

use Zero\CacheWarmer\Model\Config;
use Zero\CacheWarmer\Model\Warmer;

/**
 * Every minute: what was queued (a full warm after a deploy or a flush, or the
 * saved pages). Nothing to do is the usual case and costs one query.
 */
class Run
{
    public function __construct(
        private readonly Warmer $warmer,
        private readonly Config $config
    ) {
    }

    public function execute(): void
    {
        if ($this->config->isEnabled()) {
            $this->warmer->runQueue();
        }
    }
}
