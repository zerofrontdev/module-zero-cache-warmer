<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Test\Unit\Model;

use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\CacheWarmer\Model\Fetcher;
use Zero\CacheWarmer\Model\Queue;
use Zero\CacheWarmer\Model\Config;
use Zero\CacheWarmer\Model\Urls;
use Zero\CacheWarmer\Model\Warmer;

/**
 * How answers are counted, the paths kept once in order, the extra paths
 * setting read safely, and a run that waited its budget for the load stops
 * (a queued one goes back in the queue).
 */
class WarmerTest extends TestCase
{
    public function testAnswersAreCounted(): void
    {
        $this->assertSame('ok', Warmer::outcome(200));
        $this->assertSame('missing', Warmer::outcome(301));
        $this->assertSame('missing', Warmer::outcome(404));
        $this->assertSame('errors', Warmer::outcome(503));
        $this->assertSame('errors', Warmer::outcome(0));
    }

    public function testAnotherStorefrontsHomeUnderAPathHasNoTrailingSlash(): void
    {
        $this->assertSame('https://edge.example/uk', Warmer::url(['https://edge.example/uk/', [''], true], ''));
        $this->assertSame('https://edge.example/uk/bag.html', Warmer::url(['https://edge.example/uk/', ['bag.html'], true], 'bag.html'));
        $this->assertSame('https://edge.example/', Warmer::url(['https://edge.example/', [''], true], ''));
        $this->assertSame('https://store.example/uk/', Warmer::url(['https://store.example/uk/', ['']], ''));
    }

    public function testPathsOnceInTheirFirstPlace(): void
    {
        $this->assertSame(['', 'men.html', 'about-us', 'brands'], Urls::unique(['', 'men.html', '/about-us', 'men.html', 'brands', '/']));
    }

    public function testExtraPathsAreCleaned(): void
    {
        $this->assertSame(['brands', 'blog', 'sale/summer'], Config::lines("brands\n/blog/\r\n\n sale/summer \n../etc\nhttps://x.y/z\nbrands"));
    }

    public function testAStorefrontThatDoesNotAnswerIsSkippedAfterAFewPages(): void
    {
        $fetcher = $this->createMock(Fetcher::class);
        $fetcher->method('varnish')->willReturn(null);
        // The first storefront never answers, the second one does.
        $fetcher->expects($this->exactly(Warmer::GIVE_UP_AFTER + 3))->method('fetch')
            ->willReturnCallback(static fn (string $url) => [str_contains($url, 'down.example') ? 0 : 200, 1.0]);
        $config = $this->createStub(Config::class);
        $config->method('delayMs')->willReturn(0);
        $config->method('maxLoad')->willReturn(1000.0);
        $warmer = new Warmer(
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(Urls::class),
            $fetcher,
            $config,
            $this->createStub(Queue::class),
            $this->createStub(LockManagerInterface::class),
            $this->createStub(FileDriver::class),
            $this->createStub(LoggerInterface::class)
        );
        $run = new \ReflectionMethod($warmer, 'run');
        $stats = $run->invoke($warmer, [
            ['http://down.example/', [array_map(static fn ($i) => "p$i.html", range(1, 40))]],
            ['http://up.example/', [['a.html', 'b.html', 'c.html']]],
        ], null);

        $this->assertSame(Warmer::GIVE_UP_AFTER, $stats['errors']);
        $this->assertSame(40 - Warmer::GIVE_UP_AFTER, $stats['skipped']);
        $this->assertSame(3, $stats['ok'], 'the next storefront is still warmed');
        $this->assertStringContainsString('35 skipped', Warmer::summary($stats));
    }

    public function testHighLoadStopsTheRunAfterTheBudget(): void
    {
        $fetcher = $this->createMock(Fetcher::class);
        $fetcher->method('varnish')->willReturn(null);
        // 60 pauses of 5 s per page, then it is fetched anyway: 1 200 s is spent on the first four.
        $fetcher->expects($this->exactly(4))->method('fetch')->willReturn([200, 1.0]);
        $warmer = $this->loaded($fetcher, $this->createStub(Queue::class));

        $stats = (new \ReflectionMethod($warmer, 'run'))->invoke($warmer, [
            ['http://a.example/', [['1', '2', '3', '4', '5', '6']]],
            ['http://b.example/', [['x', 'y', 'z']]],
        ], null);

        $this->assertSame(1, $stats['stopped']);
        $this->assertSame(4, $stats['ok']);
        $this->assertSame(9 - 4, $stats['skipped'], 'every page after the stop, of both storefronts');
        $this->assertSame(1200, array_sum($warmer->paused));
        $this->assertLessThanOrEqual(1200 + 5, array_sum($warmer->paused));
        $this->assertSame(
            '4 pages in 0.0 s: 4 cached, 0 not found or redirected, 0 failed; 5 skipped; stopped after 20 min of high load',
            Warmer::summary(['seconds' => 0.0] + $stats)
        );
    }

    public function testTheBudgetIsPerRun(): void
    {
        $fetcher = $this->createStub(Fetcher::class);
        $fetcher->method('fetch')->willReturn([200, 1.0]);
        $warmer = $this->loaded($fetcher, $this->createStub(Queue::class));
        $run = new \ReflectionMethod($warmer, 'run');

        $first = $run->invoke($warmer, [['http://a.example/', [['1', '2', '3', '4', '5']]]], null);
        $second = $run->invoke($warmer, [['http://a.example/', [['1', '2', '3', '4', '5']]]], null);

        $this->assertSame([4, 1], [$first['ok'], $first['stopped']]);
        $this->assertSame([4, 1], [$second['ok'], $second['stopped']], 'a new run waits its own 20 min again');
    }

    public function testAStoppedQueuedRunIsQueuedAgain(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('take')->willReturn(['full' => false, 'ids' => ['product' => [5], 'category' => []]]);
        $added = [];
        $queue->expects($this->exactly(2))->method('add')->willReturnCallback(static function (string $type, array $ids) use (&$added) {
            $added[] = [$type, $ids];
        });
        $queue->expects($this->never())->method('full');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('stopped after 20 min of high load, the rest queued for the next run (saved pages)'));
        $warmer = $this->loaded($this->createStub(Fetcher::class), $queue, $logger, ['p1', 'p2', 'p3', 'p4', 'p5']);

        $stats = $warmer->runQueue();

        $this->assertSame(1, $stats['stopped']);
        $this->assertSame(1, $stats['requeued']);
        $this->assertSame([['product', [5]], ['category', []]], $added);
    }

    public function testAStoppedFullRunQueuesAFullRunAgain(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('take')->willReturn(['full' => true, 'stores' => [], 'ids' => []]);
        $queue->expects($this->once())->method('full')->with([]);
        $queue->expects($this->never())->method('add');
        $warmer = $this->loaded($this->createStub(Fetcher::class), $queue, null, ['p1', 'p2', 'p3', 'p4', 'p5']);

        $this->assertSame(1, $warmer->runQueue()['stopped']);
    }

    public function testAQueuedRunThatFinishesIsNotQueuedAgain(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('take')->willReturn(['full' => false, 'ids' => ['product' => [5]]]);
        $queue->expects($this->never())->method('add');
        $queue->expects($this->never())->method('full');
        $warmer = $this->loaded($this->createStub(Fetcher::class), $queue, null, ['p1', 'p2'], 0.5);

        $stats = $warmer->runQueue();

        $this->assertSame(0, $stats['stopped']);
        $this->assertArrayNotHasKey('requeued', $stats);
        $this->assertSame([], $warmer->paused, 'a load under the limit never waits');
    }

    public function testTheOrderGoesByTierAcrossStoreViewsAndAsksAUrlOnce(): void
    {
        $home = Urls::TIER_HOME;
        $top = Urls::TIER_TOP_CATEGORIES;
        $products = Urls::TIER_PRODUCTS;
        $jobs = Warmer::order([
            // Two store views on one address (default and europe_eur on demo.zerofront.dev/).
            ['https://demo.example/', [$home => [''], $top => ['women.html', 'men.html'], $products => ['bag.html']]],
            ['https://demo.example/', [$home => [''], $top => ['women.html', 'gear.html'], $products => ['bag.html']]],
            ['https://demo.example/beam/', [$home => [''], $top => ['lamps.html'], $products => ['lamp.html']]],
            // The headless storefront of BEAM: the same paths, asked directly.
            ['https://edge.example/', [$home => [''], $top => ['lamps.html'], $products => ['lamp.html']], true],
        ]);

        $this->assertSame([
            'https://demo.example/',
            'https://demo.example/beam/',
            'https://edge.example/',
            'https://demo.example/women.html',
            'https://demo.example/beam/lamps.html',
            'https://edge.example/lamps.html',
            'https://demo.example/men.html',
            'https://demo.example/gear.html',
            'https://demo.example/bag.html',
            'https://demo.example/beam/lamp.html',
            'https://edge.example/lamp.html',
        ], array_column($jobs, 0), 'every home page, then every top category, the products last; each URL once');
        $this->assertSame([false, 2, true], [$jobs[1][2], $jobs[1][1], $jobs[2][2]], 'storefront index and direct are kept');
    }

    public function testAQueuedWarmOfSomeStoreViewsWarmsOnlyThemAndItsSavedPages(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('take')->willReturn(['full' => true, 'stores' => ['beam'], 'ids' => ['product' => [5]]]);
        $queue->expects($this->once())->method('full')->with(['beam']);
        $queue->expects($this->once())->method('add')->with('product', [5]);
        $beam = $this->createStub(Store::class);
        $beam->method('getIsActive')->willReturn(true);
        $beam->method('getId')->willReturn(2);
        $beam->method('getCode')->willReturn('beam');
        $beam->method('getBaseUrl')->willReturn('http://a.example/beam/');
        $other = $this->createStub(Store::class);
        $other->method('getIsActive')->willReturn(true);
        $other->method('getId')->willReturn(1);
        $other->method('getCode')->willReturn('default');
        $other->method('getBaseUrl')->willReturn('http://a.example/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$other, $beam]);
        $urls = $this->createMock(Urls::class);
        $urls->expects($this->once())->method('forStore')->with(2)->willReturn([[''], ['lamps.html', 'chairs.html']]);
        $urls->method('forEntities')->willReturn(['bag.html']);
        $fetched = [];
        $fetcher = $this->createStub(Fetcher::class);
        $fetcher->method('fetch')->willReturnCallback(static function (string $url) use (&$fetched) {
            $fetched[] = $url;
            return [200, 1.0];
        });
        $config = $this->createStub(Config::class);
        $config->method('maxLoad')->willReturn(1.0);
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('(full: beam; and saved pages)'));
        $warmer = new class ($storeManager, $urls, $fetcher, $config, $queue, $locks, $this->createStub(FileDriver::class), $logger) extends Warmer {
            protected function load(): ?float
            {
                // High for good: four pages are fetched anyway (5 min each), then the run stops and is queued again.
                return 99.0;
            }

            protected function pause(int $seconds): void
            {
            }
        };

        $stats = $warmer->runQueue();

        $this->assertSame(
            ['http://a.example/beam/', 'http://a.example/bag.html', 'http://a.example/beam/bag.html', 'http://a.example/beam/lamps.html'],
            $fetched,
            'only BEAM\'s full warm, plus the saved product in every store view, the home page and saved pages first'
        );
        $this->assertSame(1, $stats['stopped']);
    }

    /**
     * A warmer on one store view whose load stays at $load (limit 1), pausing on paper.
     *
     * @param string[] $paths the store view's pages for a queued run
     */
    private function loaded(
        Fetcher $fetcher,
        Queue $queue,
        ?LoggerInterface $logger = null,
        array $paths = [],
        float $load = 99.0
    ): Warmer {
        $config = $this->createStub(Config::class);
        $config->method('delayMs')->willReturn(0);
        $config->method('maxLoad')->willReturn(1.0);
        $store = $this->createStub(Store::class);
        $store->method('getIsActive')->willReturn(true);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('http://a.example/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);
        $urls = $this->createStub(Urls::class);
        $urls->method('forEntities')->willReturn($paths);
        $urls->method('forStore')->willReturn([$paths]);
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);
        $fetcher->method('fetch')->willReturn([200, 1.0]);

        return new class ($load, $storeManager, $urls, $fetcher, $config, $queue, $locks, $this->createStub(FileDriver::class), $logger ?? $this->createStub(LoggerInterface::class)) extends Warmer {
            /** @var int[] */
            public array $paused = [];

            public function __construct(private readonly float $fakeLoad, ...$args)
            {
                parent::__construct(...$args);
            }

            protected function load(): ?float
            {
                return $this->fakeLoad;
            }

            protected function pause(int $seconds): void
            {
                $this->paused[] = $seconds;
            }
        };
    }
}
