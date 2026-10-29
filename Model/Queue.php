<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Model;

use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * What the next cron run warms: everything (after a deploy or a flush), or the
 * pages saved since the last run. Kept in Magento's `flag` table; every change
 * runs under a short lock, so a save and the cron taking the queue can't lose
 * each other's entries.
 */
class Queue
{
    public const FLAG = 'zero_cache_warmer_queue';
    public const TYPES = ['product', 'category', 'cms-page'];
    /** Saved pages kept per type; an import of thousands of products doesn't queue thousands of pages. */
    public const MAX_IDS = 500;
    private const LOCK = 'zero_cache_warmer_queue';

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly LockManagerInterface $locks
    ) {
    }

    public function full(): void
    {
        $this->change(static fn (array $queue) => ['full' => true] + $queue);
    }

    /**
     * @param int[] $ids
     */
    public function add(string $type, array $ids): void
    {
        if (!in_array($type, self::TYPES, true) || $ids === []) {
            return;
        }
        $this->change(static fn (array $queue) => self::merge($queue, $type, $ids));
    }

    /**
     * The queue as it was, emptied.
     *
     * @return array{full: bool, ids: array<string, int[]>}
     */
    public function take(): array
    {
        $taken = [];
        $this->change(static function (array $queue) use (&$taken): array {
            $taken = $queue;
            return [];
        });

        return [
            'full' => !empty($taken['full']),
            'ids' => array_map(static fn ($ids) => array_map('intval', array_keys((array) $ids)), (array) ($taken['ids'] ?? [])),
        ];
    }

    /**
     * @param int[] $ids
     */
    public static function merge(array $queue, string $type, array $ids): array
    {
        $current = (array) ($queue['ids'][$type] ?? []);
        foreach ($ids as $id) {
            if ((int) $id > 0 && count($current) < self::MAX_IDS) {
                $current[(int) $id] = 1;
            }
        }
        $queue['ids'][$type] = $current;

        return $queue;
    }

    private function change(callable $change): void
    {
        if (!$this->locks->lock(self::LOCK, 5)) {
            return;
        }
        try {
            $queue = $change((array) ($this->flagManager->getFlagData(self::FLAG) ?: []));
            $queue === [] ? $this->flagManager->deleteFlag(self::FLAG) : $this->flagManager->saveFlag(self::FLAG, $queue);
        } finally {
            $this->locks->unlock(self::LOCK);
        }
    }
}
