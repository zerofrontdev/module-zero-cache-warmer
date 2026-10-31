<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Store\Model\ScopeInterface;

/**
 * Which paths of a store view to warm, read from the URL rewrites (the URLs
 * visitors actually use, path prefix stores included), in priority tiers:
 * the home page, the top-level categories, the deeper categories (as many as
 * set, top levels first), the CMS pages, the configured extra paths, then the
 * products: the best sellers of the last 90 days, then the newest. Each path
 * once, relative to the store view's URL. Rewrites that can only answer 404
 * are left out here: categories outside the store view's root category, and
 * URLs with an empty URL key ("/.html").
 */
class Urls
{
    /** The tiers forStore() returns, highest priority first; the same index means the same tier in every store view. */
    public const TIER_HOME = 0;
    public const TIER_TOP_CATEGORIES = 1;
    public const TIER_CATEGORIES = 2;
    public const TIER_PAGES = 3;
    public const TIER_EXTRA = 4;
    public const TIER_PRODUCTS = 5;

    private const BESTSELLER_DAYS = 90;

    /** @var array<int, int> store id => root category id */
    private array $roots = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * The store view's paths by tier (TIER_* index => paths); every tier is there, empty or not.
     *
     * @param string[] $extraPaths
     * @return array<int, string[]>
     */
    public function forStore(int $storeId, int $products, array $extraPaths, ?int $categoryLimit = null): array
    {
        $connection = $this->resource->getConnection();
        $categories = $categoryLimit === 0 ? [] : $connection->fetchCol(
            $this->rewrites($storeId, 'category')
                ->order(new \Zend_Db_Expr("LENGTH(u.request_path) - LENGTH(REPLACE(u.request_path, '/', ''))"))
                ->order('u.request_path')
                ->limit($categoryLimit)
        );
        $home = strtok((string) $this->scopeConfig->getValue('web/default/cms_home_page', ScopeInterface::SCOPE_STORE, $storeId), '|');
        $pages = $connection->fetchCol(
            $this->rewrites($storeId, 'cms-page')->where('u.request_path NOT IN (?)', [(string) $home, 'no-route'])->order('u.request_path')
        );
        $top = array_values(array_filter($categories, static fn ($path) => !str_contains(trim((string) $path, '/'), '/')));

        return self::tiers([
            self::TIER_HOME => [''],
            self::TIER_TOP_CATEGORIES => $top,
            self::TIER_CATEGORIES => array_values(array_diff($categories, $top)),
            self::TIER_PAGES => $pages,
            self::TIER_EXTRA => $extraPaths,
            self::TIER_PRODUCTS => $this->products($storeId, $products),
        ]);
    }

    /**
     * The pages of saved products (with their categories), categories and CMS pages.
     *
     * @param array<string, int[]> $ids type => entity ids
     * @return string[]
     */
    public function forEntities(int $storeId, array $ids): array
    {
        $connection = $this->resource->getConnection();
        $productIds = $ids['product'] ?? [];
        $categoryIds = $ids['category'] ?? [];
        if ($productIds !== []) {
            $categoryIds = [...$categoryIds, ...$connection->fetchCol(
                $connection->select()->distinct()
                    ->from($this->resource->getTableName('catalog_category_product'), ['category_id'])
                    ->where('product_id IN (?)', $productIds)
            )];
        }
        $paths = [];
        foreach (['product' => $productIds, 'category' => $categoryIds, 'cms-page' => $ids['cms-page'] ?? []] as $type => $entityIds) {
            if ($entityIds !== []) {
                $paths = [...$paths, ...$connection->fetchCol(
                    $this->rewrites($storeId, $type)->where('u.entity_id IN (?)', array_map('intval', $entityIds))
                )];
            }
        }

        return self::unique($paths);
    }

    /**
     * @return string[]
     */
    private function products(int $storeId, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $best = $connection->fetchCol(
            $connection->select()
                ->from(['i' => $this->resource->getTableName('sales_order_item')], ['product_id'])
                ->join(['o' => $this->resource->getTableName('sales_order')], 'o.entity_id = i.order_id', [])
                ->where('o.store_id = ?', $storeId)
                ->where('i.parent_item_id IS NULL')
                ->where('o.created_at > ?', gmdate('Y-m-d H:i:s', time() - self::BESTSELLER_DAYS * 86400))
                ->group('i.product_id')
                ->order(new \Zend_Db_Expr('SUM(i.qty_ordered) DESC'))
                ->limit($limit)
        );
        $paths = [];
        if ($best !== []) {
            $byId = $connection->fetchPairs(
                $this->rewrites($storeId, 'product', ['entity_id', 'request_path'])->where('u.entity_id IN (?)', array_map('intval', $best))
            );
            foreach ($best as $id) {
                if (isset($byId[$id])) {
                    $paths[] = $byId[$id];
                }
            }
        }
        if (count($paths) < $limit) {
            $paths = [...$paths, ...$connection->fetchCol(
                $this->rewrites($storeId, 'product')->order('u.entity_id DESC')->limit($limit * 2)
            )];
        }

        return array_slice(self::unique($paths), 0, $limit);
    }

    /**
     * Direct (not redirecting) rewrites of one type in a store view (table alias `u`); products
     * only at their own URL, not under each category; categories only under the store view's
     * root category; no URL with an empty key (".html", "women/.html"), which only answers 404.
     *
     * @param string[] $columns
     */
    private function rewrites(int $storeId, string $type, array $columns = ['request_path']): Select
    {
        $select = $this->resource->getConnection()->select()
            ->from(['u' => $this->resource->getTableName('url_rewrite')], $columns)
            ->where('u.store_id = ?', $storeId)
            ->where('u.entity_type = ?', $type)
            ->where('u.redirect_type = 0')
            ->where("u.request_path NOT LIKE '.%'")
            ->where("u.request_path NOT LIKE '%/.%'");
        if ($type === 'product') {
            $select->where('u.metadata IS NULL');
        }
        if ($type === 'category') {
            $select->join(['e' => $this->resource->getTableName('catalog_category_entity')], 'e.entity_id = u.entity_id', [])
                ->where('e.path LIKE ?', '1/' . $this->rootCategory($storeId) . '/%');
        }

        return $select;
    }

    /** The root category of the store view's store (group). */
    private function rootCategory(int $storeId): int
    {
        if (!isset($this->roots[$storeId])) {
            $connection = $this->resource->getConnection();
            $this->roots[$storeId] = (int) $connection->fetchOne(
                $connection->select()
                    ->from(['s' => $this->resource->getTableName('store')], [])
                    ->join(['g' => $this->resource->getTableName('store_group')], 'g.group_id = s.group_id', ['root_category_id'])
                    ->where('s.store_id = ?', $storeId)
            );
        }

        return $this->roots[$storeId];
    }

    /**
     * Each path once across the tiers, in the first (highest) tier it is in; the tier keys stay.
     *
     * @param array<int, string[]> $tiers
     * @return array<int, string[]>
     */
    public static function tiers(array $tiers): array
    {
        $seen = [];
        foreach ($tiers as $tier => $paths) {
            $kept = [];
            foreach (self::unique($paths) as $path) {
                if (!isset($seen[$path])) {
                    $seen[$path] = true;
                    $kept[] = $path;
                }
            }
            $tiers[$tier] = $kept;
        }

        return $tiers;
    }

    /**
     * @param string[] $paths
     * @return string[]
     */
    public static function unique(array $paths): array
    {
        $seen = [];
        foreach ($paths as $path) {
            $seen[ltrim((string) $path, '/')] = true;
        }

        return array_map('strval', array_keys($seen));
    }
}
