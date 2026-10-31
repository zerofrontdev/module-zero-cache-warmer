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
 * run at a time (the cron and bin/magento zero:cache:warm share a lock). A run
 * waits for the load LOAD_WAIT_BUDGET seconds at most in all; then it stops,
 * and a queued run puts what it took back in the queue for the next one.
 *
 * The order is by priority across the store views, not store view by store
 * view: every storefront's home page first, then every top-level category,
 * and so on (Urls' tiers), taking one page of each storefront in turn within a
 * tier. A URL is asked once per run, even when store views share an address.
 */
class Warmer
{
    /** Pages of one storefront in a row without an answer, after which its other pages are skipped. */
    public const GIVE_UP_AFTER = 5;

    public const LOCK = 'zero_cache_warmer_run';
    /** While the load is too high: look again this often, at most this many times. */
    private const LOAD_WAIT_SECONDS = 5;
    private const LOAD_WAIT_TRIES = 60;
    /** Seconds of load waiting per run, all pages together, after which the run stops. */
    private const LOAD_WAIT_BUDGET = 1200;

    private ?float $maxLoad = null;
    /** Seconds this run has waited for the load so far. */
    private int $waited = 0;

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
            $stores = $queued['stores'] ?? [];
            // A full warm of every store view covers the saved pages; one of some store views doesn't.
            $saved = (!$queued['full'] || $stores !== []) && array_filter($queued['ids']);
            if (!$queued['full'] && !$saved) {
                return null;
            }
            $plan = [
                ...($queued['full'] ? $this->allPaths($stores, $this->config->products()) : []),
                ...($saved ? $this->entityPaths($queued['ids']) : []),
            ];
            $stats = $this->run($plan, null);
            if (!empty($stats['stopped'])) {
                // Stopped under load: everything taken goes back, the next run starts it again.
                if ($queued['full']) {
                    $this->queue->full($stores);
                }
                if ($saved) {
                    foreach ($queued['ids'] as $type => $ids) {
                        $this->queue->add((string) $type, $ids);
                    }
                }
                $stats['requeued'] = 1;
            }
            $this->logger->info('Zero Cache Warmer: ' . self::summary($stats) . match (true) {
                !$queued['full'] => ' (saved pages)',
                $stores === [] => ' (full)',
                default => ' (full: ' . implode(', ', $stores) . ($saved ? '; and saved pages' : '') . ')',
            });

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
        return array_column(self::order($this->allPaths($storeCodes, $products)), 0);
    }

    /**
     * @return array<int, array{0: string, 1: array<int, string[]>, 2?: bool}> per store view: its URL and the
     *     paths under it by tier, then each other storefront's (direct: requested at that address, not through Varnish)
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
     * @param array<int, string[]> $paths by tier
     * @return array<int, array{0: string, 1: array<int, string[]>, 2?: bool}>
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
     * @return array<int, array{0: string, 1: array<int, string[]>, 2?: bool}>
     */
    private function entityPaths(array $ids): array
    {
        $plan = [];
        foreach ($this->stores([]) as $store) {
            $plan = [...$plan, ...$this->withTargets($store, [$this->urls->forEntities((int) $store->getId(), $ids)])];
        }

        return $plan;
    }

    /**
     * The plan as the pages to ask, in order: tier by tier; within a tier one page of each storefront in turn, so
     * no store view waits for another one's whole list. A URL asked the same way (through Varnish or directly) is
     * kept once, in its first place: store views that share an address share its cache entries.
     *
     * @param array<int, array{0: string, 1: array<int, string[]>, 2?: bool}> $plan
     * @return array<int, array{0: string, 1: int, 2: bool}> URL, the storefront's index in the plan, direct
     */
    public static function order(array $plan): array
    {
        $tiers = [];
        foreach ($plan as $entry) {
            $tiers = [...$tiers, ...array_keys($entry[1])];
        }
        $tiers = array_unique($tiers);
        sort($tiers);
        $jobs = [];
        $seen = [];
        foreach ($tiers as $tier) {
            $lists = array_map(static fn (array $entry) => array_values($entry[1][$tier] ?? []), $plan);
            $longest = $lists === [] ? 0 : max(array_map('count', $lists));
            for ($i = 0; $i < $longest; $i++) {
                foreach ($lists as $source => $paths) {
                    if (!isset($paths[$i])) {
                        continue;
                    }
                    $direct = !empty($plan[$source][2]);
                    $url = self::url($plan[$source], (string) $paths[$i]);
                    $key = ($direct ? 'direct ' : 'varnish ') . $url;
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $jobs[] = [$url, $source, $direct];
                    }
                }
            }
        }

        return $jobs;
    }

    /**
     * @param array<int, array{0: string, 1: array<int, string[]>, 2?: bool}> $plan
     * @return array<string, int|float>
     */
    private function run(array $plan, ?callable $onUrl): array
    {
        $varnish = $this->fetcher->varnish();
        $delay = $this->config->delayMs() * 1000;
        $stats = ['urls' => 0, 'ok' => 0, 'missing' => 0, 'errors' => 0, 'skipped' => 0, 'stopped' => 0, 'seconds' => 0.0];
        $start = microtime(true);
        $this->waited = 0;
        /** @var array<int, int> $silent storefront => its pages in a row without an answer */
        $silent = [];
        foreach (self::order($plan) as [$url, $source, $direct]) {
            // A storefront that answered none of its last few pages is down or out of reach: its other pages are
            // skipped, not waited for one by one (a test server that could not reach itself waited for hours).
            // Once the load stayed high past the run's budget, every later page waits for the next run.
            if ($stats['stopped'] || ($silent[$source] ?? 0) >= self::GIVE_UP_AFTER) {
                $stats['skipped']++;
                continue;
            }
            if (!$this->waitForLoad()) {
                $stats['stopped'] = 1;
                $stats['skipped']++;
                continue;
            }
            [$status, $ms] = $this->fetcher->fetch($url, $direct ? null : $varnish);
            $silent[$source] = $status === 0 ? ($silent[$source] ?? 0) + 1 : 0;
            $stats['urls']++;
            $stats[self::outcome($status)]++;
            if ($onUrl) {
                $onUrl($url, $status, $ms);
            }
            if ($delay > 0) {
                usleep($delay);
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
        ) . (!empty($stats['skipped'])
            ? sprintf(empty($stats['stopped']) ? '; %d skipped (a storefront did not answer)' : '; %d skipped', $stats['skipped'])
            : '') . (!empty($stats['stopped'])
            ? '; stopped after 20 min of high load' . (!empty($stats['requeued']) ? ', the rest queued for the next run' : '')
            : '');
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

    /**
     * Waits while the load is above the limit: up to LOAD_WAIT_TRIES × LOAD_WAIT_SECONDS for a page, then it is
     * fetched anyway. False once the run's LOAD_WAIT_BUDGET is spent and the load is still high: the run stops.
     */
    private function waitForLoad(): bool
    {
        $max = $this->maxLoad ??= $this->config->maxLoad() ?: $this->cores();
        for ($try = 0; $try < self::LOAD_WAIT_TRIES; $try++) {
            $load = $this->load();
            if ($load === null || $load <= $max) {
                return true;
            }
            if ($this->waited >= self::LOAD_WAIT_BUDGET) {
                return false;
            }
            $this->pause(self::LOAD_WAIT_SECONDS);
            $this->waited += self::LOAD_WAIT_SECONDS;
        }

        return true;
    }

    /** The one-minute load average, null where the system has none. */
    protected function load(): ?float
    {
        $load = sys_getloadavg();

        return $load ? (float) $load[0] : null;
    }

    protected function pause(int $seconds): void
    {
        sleep($seconds);
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
