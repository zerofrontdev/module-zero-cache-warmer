<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Zero\CacheWarmer\Model\Urls;

/**
 * The plan of a store view by tier, and the rewrites that can only answer 404 left out at the query: categories
 * outside the store view's root category, URLs with an empty URL key ("/.html").
 */
class UrlsTest extends TestCase
{
    /** @var array<int, array{0: string, 1: mixed}> every where() of every query */
    private array $where = [];
    /** @var array<int, array> every join() */
    private array $joins = [];

    public function testTiersAndSourceFilters(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->select());
        $connection->method('fetchOne')->willReturn('2');
        // Categories (top level first, as the query orders them), then the CMS pages, then the newest products.
        $connection->method('fetchCol')->willReturnOnConsecutiveCalls(
            ['men.html', 'women.html', 'men/tops-men.html'],
            ['about-us', 'men.html'],
            [],
            ['bag.html']
        );
        $connection->method('fetchPairs')->willReturn([]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturn('home|1');

        $tiers = (new Urls($resource, $scope))->forStore(1, 1, ['brands', 'about-us'], 500);

        $this->assertSame([
            Urls::TIER_HOME => [''],
            Urls::TIER_TOP_CATEGORIES => ['men.html', 'women.html'],
            Urls::TIER_CATEGORIES => ['men/tops-men.html'],
            Urls::TIER_PAGES => ['about-us'],
            Urls::TIER_EXTRA => ['brands'],
            Urls::TIER_PRODUCTS => ['bag.html'],
        ], $tiers, 'each path once, in its highest tier');

        $conditions = array_column($this->where, 0);
        $this->assertContains("u.request_path NOT LIKE '.%'", $conditions, 'no ".html" (an empty URL key)');
        $this->assertContains("u.request_path NOT LIKE '%/.%'", $conditions, 'no "women/.html"');
        $this->assertContains(['e.path LIKE ?', '1/2/%'], $this->where, 'categories only under the root category (2)');
        $this->assertContains('e.entity_id = u.entity_id', array_column($this->joins, 1), 'the category join');
    }

    public function testATierKeepsItsPlaceWhenEmpty(): void
    {
        $this->assertSame([0 => ['', 'a'], 1 => [], 2 => ['b']], Urls::tiers([0 => ['', 'a'], 1 => ['a'], 2 => ['b', '/a']]));
    }

    private function select(): Select
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'order', 'limit', 'distinct', 'group'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select) {
            $this->where[] = [$condition, $value];
            return $select;
        });
        $select->method('join')->willReturnCallback(function (...$args) use ($select) {
            $this->joins[] = array_values(array_map(static fn ($arg) => is_array($arg) ? reset($arg) : $arg, $args));
            return $select;
        });

        return $select;
    }
}
