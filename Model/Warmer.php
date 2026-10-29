<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Model;

use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Requests the pages Urls picks, one at a time with a pause, and waits while
 * the server is busy: visitors come first, the warmer takes what is left. One
 * run at a time (the cron and bin/magento zero:cache:warm share a lock).
 */
class Warmer
{
    /** Pages of one storefront in a row without an answer, after which its other pages are skipped. */
    public const GIVE_UP_AFTER = 5;

    public const LOCK = 'zero_cache_warmer_run';
    /** While the load is too high: look again this often, at most this many times. */
    private const LOAD_WAIT_SECONDS = 5;
    private const LOAD_WAIT_TRIES = 60;

    private ?float $maxLoad = null;

    /**
     * @param ExtraTargetInterface[] $targets other storefronts of the same store views (di.xml)
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Urls $urls,
        private readonly Fetcher $fetcher,
        private readonly Config $config,
        private readonly Queue $queue,
        private readonly LockManagerInterface $locks,
        private readonly FileDriver $fileDriver,
        private readonly LoggerInterface $logger,
        private readonly array $targets = []
    ) {
    }

    /**
     * Whatever the queue holds: everything after a deploy or a flush, else the saved pages.
     *
     * @return array<string, int|float>|null the run's numbers, null when there was nothing to do
     */
    public function runQueue(): ?array
    {
        if (!$this->locks->lock(self::LOCK, 0)) {
            return null;
        }
        try {
            $queued = $this->queue->take();
            if ($queued['full']) {
                $stats = $this->run($this->allPaths([], $this->config->products()), null);
            } elseif (array_filter($queued['ids'])) {
                $stats = $this->run($this->entityPaths($queued['ids']), null);
            } else {
                return null;
            }
            $this->logger->info('Zero Cache Warmer: ' . self::summary($stats) . ($queued['full'] ? ' (full)' : ' (saved pages)'));

            return $stats;
        } finally {
            $this->locks->unlock(self::LOCK);
        }
    }

    /**
     * @param string[] $storeCodes store view codes; empty: every active store view
     * @param callable(string, int, float): void|null $onUrl called after each page (URL, status, ms)
     * @return array<string, int|float>|null null when another run holds the lock
     */
    public function warmAll(array $storeCodes, int $products, ?callable $onUrl = null): ?array
    {
        if (!$this->locks->lock(self::LOCK, 0)) {
            return null;
        }
        try {
            return $this->run($this->allPaths($storeCodes, $products), $onUrl);
        } finally {
            $this->locks->unlock(self::LOCK);
        }
    }

    /**
     * The URLs a full run would request, without requesting them.
     *
     * @param string[] $storeCodes
     * @return string[]
     */
    public function plan(array $storeCodes, int $products): array
    {
        $urls = [];
        foreach ($this->allPaths($storeCodes, $products) as $entry) {
            foreach ($entry[1] as $path) {
                $urls[] = self::url($entry, $path);
            }
        }

        return $urls;
    }

    /**
     * @return array<int, array{0: string, 1: string[], 2?: bool}> per store view: its URL and the paths under it,
     *     then each other storefront's (direct: requested at that address, not through Varnish)
     */
    private function allPaths(array $storeCodes, int $products): array
    {
        $extra = $this->config->extraPaths();
        $categories = $this->config->categories();
        $plan = [];
        foreach ($this->stores($storeCodes) as $store) {
            $plan = [...$plan, ...$this->withTargets($store, $this->urls->forStore((int) $store->getId(), $products, $extra, $categories))];
        }

        return $plan;
    }

    /**
     * The store view's own address with its paths, then the other storefronts' with the same paths.
     *
     * @param string[] $paths
     * @return array<int, array{0: string, 1: string[], 2?: bool}>
     */
    private function withTargets(StoreInterface $store, array $paths): array
    {
        $plan = [[$this->baseUrl($store), $paths]];
        foreach ($this->targets as $target) {
            foreach ($target->bases($store) as $base) {
                $plan[] = [rtrim($base, '/') . '/', $paths, true];
            }
        }

        return $plan;
    }

    /**
     * @param array<string, int[]> $ids
     * @return array<int, array{0: string, 1: string[], 2?: bool}>
     */
    private function entityPaths(array $ids): array
    {
        $plan = [];
        foreach ($this->stores([]) as $store) {
            $plan = [...$plan, ...$this->withTargets($store, $this->urls->forEntities((int) $store->getId(), $ids))];
        }

        return $plan;
    }

    /**
     * @param array<int, array{0: string, 1: string[], 2?: bool}> $plan
     * @return array<string, int|float>
     */
    private function run(array $plan, ?callable $onUrl): array
    {
        $varnish = $this->fetcher->varnish();
        $delay = $this->config->delayMs() * 1000;
        $stats = ['urls' => 0, 'ok' => 0, 'missing' => 0, 'errors' => 0, 'skipped' => 0, 'seconds' => 0.0];
        $start = microtime(true);
        foreach ($plan as $entry) {
            [$base, $paths] = $entry;
            $silent = 0;
            foreach ($paths as $i => $path) {
                // A storefront that answered none of the last few pages is down or out of reach: its other pages are
                // skipped, not waited for one by one (a test server that could not reach itself waited for hours).
                if ($silent >= self::GIVE_UP_AFTER) {
                    $stats['skipped'] += count($paths) - $i;
                    break;
                }
                $this->waitForLoad();
                $url = self::url($entry, $path);
                [$status, $ms] = $this->fetcher->fetch($url, empty($entry[2]) ? $varnish : null);
                $silent = $status === 0 ? $silent + 1 : 0;
                $stats['urls']++;
                $stats[self::outcome($status)]++;
                if ($onUrl) {
                    $onUrl($url, $status, $ms);
                }
                if ($delay > 0) {
                    usleep($delay);
                }
            }
        }
        $stats['seconds'] = round(microtime(true) - $start, 1);

        return $stats;
    }

    /**
     * A plan entry's page: its base and the path; another storefront's home page under a path ("…/uk/") is its
     * address without the slash, as it answers there.
     *
     * @param array{0: string, 1: string[], 2?: bool} $entry
     */
    public static function url(array $entry, string $path): string
    {
        $base = $entry[0];

        return $path === '' && !empty($entry[2]) && parse_url($base, PHP_URL_PATH) !== '/' ? rtrim($base, '/') : $base . $path;
    }

    /** ok: cached now; missing: a page this store view doesn't have (404, a redirect); errors: no answer or 5xx. */
    public static function outcome(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 300 => 'ok',
            $status >= 300 && $status < 500 => 'missing',
            default => 'errors',
        };
    }

    /**
     * @param array<string, int|float> $stats
     */
    public static function summary(array $stats): string
    {
        return sprintf(
            '%d pages in %.1f s: %d cached, %d not found or redirected, %d failed',
            $stats['urls'],
            $stats['seconds'],
            $stats['ok'],
            $stats['missing'],
            $stats['errors']
        ) . (!empty($stats['skipped']) ? sprintf('; %d skipped (a storefront did not answer)', $stats['skipped']) : '');
    }

    /**
     * @return StoreInterface[]
     */
    private function stores(array $codes): array
    {
        $stores = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($store->getIsActive() && ($codes === [] || in_array($store->getCode(), $codes, true))) {
                $stores[] = $store;
            }
        }

        return $stores;
    }

    private function baseUrl(StoreInterface $store): string
    {
        /** @var Store $store */
        return rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_LINK, $store->isFrontUrlSecure()), '/') . '/';
    }

    private function waitForLoad(): void
    {
        $max = $this->maxLoad ??= $this->config->maxLoad() ?: $this->cores();
        for ($try = 0; $try < self::LOAD_WAIT_TRIES; $try++) {
            $load = sys_getloadavg();
            if (!$load || $load[0] <= $max) {
                return;
            }
            sleep(self::LOAD_WAIT_SECONDS);
        }
    }

    /** The CPU cores on Linux; elsewhere (no /proc) the warmer doesn't wait. */
    private function cores(): float
    {
        try {
            $cpuinfo = $this->fileDriver->fileGetContents('/proc/cpuinfo');
        } catch (\Throwable) {
            return INF;
        }
        $cores = preg_match_all('/^processor\s*:/m', $cpuinfo);

        return $cores > 0 ? (float) $cores : INF;
    }
}
