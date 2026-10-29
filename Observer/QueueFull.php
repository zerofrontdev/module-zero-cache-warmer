<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zero\CacheWarmer\Model\Config;
use Zero\CacheWarmer\Model\Queue;

/**
 * Flush Magento Cache or Flush Cache Storage in the admin: the next cron run
 * warms every store view.
 */
class QueueFull implements ObserverInterface
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Config $config
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function execute(Observer $observer): void
    {
        if ($this->config->isEnabled()) {
            $this->queue->full();
        }
    }
}
