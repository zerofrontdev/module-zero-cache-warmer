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
 * visitors actually use, path prefix stores included):
 * the home page, the categories from the top level down (as many as set), the CMS pages, the
 * configured extra paths, then the products: the best sellers of the last 90
 * days, then the newest. Each path once, relative to the store view's URL.
 */
class Urls
{
    private const BESTSELLER_DAYS = 90;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @param string[] $extraPaths
     * @return string[]
     */
    public function forStore(int $storeId, int $products, array $extraPaths, ?int $categoryLimit = null): array
    {
        $connection = $this->resource->getConnection();
        $categories = $categoryLimit === 0 ? [] : $connection->fetchCol(
            $this->rewrites($storeId, 'category')
                ->order(new \Zend_Db_Expr("LENGTH(request_path) - LENGTH(REPLACE(request_path, '/', ''))"))
                ->order('request_path')
                ->limit($categoryLimit)
        );
        $home = strtok((string) $this->scopeConfig->getValue('web/default/cms_home_page', ScopeInterface::SCOPE_STORE, $storeId), '|');
        $pages = $connection->fetchCol(
            $this->rewrites($storeId, 'cms-page')->where('request_path NOT IN (?)', [(string) $home, 'no-route'])->order('request_path')
        );

        return self::unique(['', ...$categories, ...$pages, ...$extraPaths, ...$this->products($storeId, $products)]);
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
                    $this->rewrites($storeId, $type)->where('entity_id IN (?)', array_map('intval', $entityIds))
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
                $this->rewrites($storeId, 'product', ['entity_id', 'request_path'])->where('entity_id IN (?)', array_map('intval', $best))
            );
            foreach ($best as $id) {
                if (isset($byId[$id])) {
                    $paths[] = $byId[$id];
                }
            }
        }
        if (count($paths) < $limit) {
            $paths = [...$paths, ...$connection->fetchCol(
                $this->rewrites($storeId, 'product')->order('entity_id DESC')->limit($limit * 2)
            )];
        }

        return array_slice(self::unique($paths), 0, $limit);
    }

    /**
     * Direct (not redirecting) rewrites of one type in a store view; products
     * only at their own URL, not under each category.
     *
     * @param string[] $columns
     */
    private function rewrites(int $storeId, string $type, array $columns = ['request_path']): Select
    {
        $select = $this->resource->getConnection()->select()
            ->from($this->resource->getTableName('url_rewrite'), $columns)
            ->where('store_id = ?', $storeId)
            ->where('entity_type = ?', $type)
            ->where('redirect_type = 0');
        if ($type === 'product') {
            $select->where('metadata IS NULL');
        }

        return $select;
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
