<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Test\Unit\Model;

use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\CacheWarmer\Model\Fetcher;
use Zero\CacheWarmer\Model\Queue;
use Zero\CacheWarmer\Model\Config;
use Zero\CacheWarmer\Model\Urls;
use Zero\CacheWarmer\Model\Warmer;

/**
 * How answers are counted, the paths kept once in order, and the extra paths
 * setting read safely.
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
            ['http://down.example/', array_map(static fn ($i) => "p$i.html", range(1, 40))],
            ['http://up.example/', ['a.html', 'b.html', 'c.html']],
        ], null);

        $this->assertSame(Warmer::GIVE_UP_AFTER, $stats['errors']);
        $this->assertSame(40 - Warmer::GIVE_UP_AFTER, $stats['skipped']);
        $this->assertSame(3, $stats['ok'], 'the next storefront is still warmed');
        $this->assertStringContainsString('35 skipped', Warmer::summary($stats));
    }
}
