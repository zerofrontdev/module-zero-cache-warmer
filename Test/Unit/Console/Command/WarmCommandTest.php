<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Test\Unit\Console\Command;

use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zero\CacheWarmer\Console\Command\WarmCommand;
use Zero\CacheWarmer\Model\Config;
use Zero\CacheWarmer\Model\Queue;
use Zero\CacheWarmer\Model\Warmer;

/**
 * --later queues the store views given with --store (storefront-deploy.sh queues --store=edge), not every one.
 */
class WarmCommandTest extends TestCase
{
    public function testLaterHonoursStore(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->once())->method('full')->with(['edge', 'edge_uk']);

        $output = $this->execute($queue, ['--later' => true, '--store' => 'edge, edge_uk']);

        $this->assertStringContainsString('warms the store views edge, edge_uk', $output);
    }

    public function testLaterWithoutStoreQueuesEveryStoreView(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->once())->method('full')->with([]);

        $this->assertStringContainsString('warms every store view', $this->execute($queue, ['--later' => true]));
    }

    private function execute(Queue $queue, array $input): string
    {
        $warmer = $this->createMock(Warmer::class);
        $warmer->expects($this->never())->method('warmAll');
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $tester = new CommandTester(new WarmCommand($warmer, $queue, $config, $this->createStub(State::class)));
        $this->assertSame(0, $tester->execute($input));

        return $tester->getDisplay();
    }
}
