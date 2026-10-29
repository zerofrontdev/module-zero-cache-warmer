<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Observer;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zero\CacheWarmer\Model\Config;
use Zero\CacheWarmer\Model\Queue;

/**
 * A saved product, category or CMS page: its cache entries were purged, so the
 * next cron run warms its page again (a product with its categories' pages).
 */
class QueueSaved implements ObserverInterface
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Config $config
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->warmAfterSave()) {
            return;
        }
        $object = $observer->getEvent()->getData('data_object');
        $type = match (true) {
            $object instanceof Product => 'product',
            $object instanceof Category => 'category',
            $object instanceof Page => 'cms-page',
            default => null,
        };
        if ($type !== null && (int) $object->getId() > 0) {
            $this->queue->add($type, [(int) $object->getId()]);
        }
    }
}
