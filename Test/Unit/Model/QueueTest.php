<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Test\Unit\Model;

use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Zero\CacheWarmer\Model\Queue;

/**
 * The queue: saved pages each once and at most MAX_IDS per type, "full"
 * kept alongside, taking empties it.
 */
#[AllowMockObjectsWithoutExpectations]
class QueueTest extends TestCase
{
    private ?array $flag = null;

    private function queue(): Queue
    {
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(fn () => $this->flag);
        $flags->method('saveFlag')->willReturnCallback(function ($code, $value) {
            $this->flag = $value;
            return true;
        });
        $flags->method('deleteFlag')->willReturnCallback(function () {
            $this->flag = null;
            return true;
        });
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);

        return new Queue($flags, $locks);
    }

    public function testSavedPagesAreKeptOnceAndTakingEmptiesTheQueue(): void
    {
        $queue = $this->queue();
        $queue->add('product', [5, 7]);
        $queue->add('product', [5]);
        $queue->add('cms-page', [2]);
        $queue->add('order', [9]);

        $this->assertSame(['full' => false, 'ids' => ['product' => [5, 7], 'cms-page' => [2]]], $queue->take());
        $this->assertNull($this->flag);
        $this->assertSame(['full' => false, 'ids' => []], $queue->take());
    }

    public function testFullKeepsTheSavedPages(): void
    {
        $queue = $this->queue();
        $queue->add('category', [3]);
        $queue->full();

        $this->assertSame(['full' => true, 'ids' => ['category' => [3]]], $queue->take());
    }

    public function testAnImportDoesNotQueueThousands(): void
    {
        $merged = Queue::merge([], 'product', range(1, Queue::MAX_IDS + 100));

        $this->assertCount(Queue::MAX_IDS, $merged['ids']['product']);
    }
}
