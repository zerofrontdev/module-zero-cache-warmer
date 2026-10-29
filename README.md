# Zero_CacheWarmer

After a deploy or a cache flush the full page cache is empty, and the first
visitor of every page waits while Magento builds it (half a second to several
seconds; a cached page answers in tens of milliseconds). This module requests
the pages visitors open most, once, before they do.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-cache-warmer
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-core` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## What it warms

For each active store view, in this order:

1. the home page;
2. the categories, from the top level down, up to **Categories per Store
   View** (default 500: 2 000 categories took an hour to warm, one at a time);
3. the CMS pages (not the home page's own URL, not `no-route`);
4. the paths in **Also Warm** (default `brands`, `blog`);
5. the products: the best sellers of the last 90 days, then the newest, up to
   **Products per Store View** (default 30).

URLs come from `url_rewrite`, so stores served under a path (`/beam/`) and
their own domains are covered. Pages are warmed as a guest sees them; a
signed-in customer group or another currency has its own cache entry and is
not warmed.

## When

- **After a deploy:** `scripts/after_install.sh` runs
  `bin/magento zero:cache:warm --later` (queues a full warm and returns).
- **After Flush Magento Cache / Flush Cache Storage** in the admin.
- **After a save:** a product (with its categories), a category or a CMS page
  is warmed again (setting **Warm a Page After It Is Saved**). Up to 500
  pages per type are queued, so an import doesn't queue thousands.

The queue lives in the `flag` table (`zero_cache_warmer_queue`); the cron job
`zero_cache_warmer_run` (every minute) does what it holds. One run at a time:
the cron and the command share a lock.

## How

- With Varnish (Stores > Configuration > Advanced > System > Full Page Cache =
  Varnish and `http_cache_hosts` in env.php), requests go to Varnish itself
  with the page's `Host` and `X-Forwarded-Proto` — the same cache entry the
  public HTTPS server would make, without CloudFront, TLS or the crawlers'
  speed limit in between. With Magento's own cache, the store's URL is
  requested directly.
- One request at a time, **Pause Between Requests** (default 100 ms) between
  them; while the 1-minute load average is above **Wait While the Load Is
  Above** (0: the number of CPU cores, read from `/proc/cpuinfo`) it waits,
  5 s at a time, up to 5 minutes. Visitors come first.
- User agent `ZeroCacheWarmer/1.0`. A run ends with one line in
  `var/log/system.log`: pages, seconds, cached, not found, failed.

## Command

```
bin/magento zero:cache:warm                 # every store view, now
bin/magento zero:cache:warm --store=beam,berlin --products=100 -v
bin/magento zero:cache:warm --dry-run       # only list the URLs
bin/magento zero:cache:warm --later         # leave it to the next cron run
```

## Settings

Stores > Configuration > Zero > **Cache Warmer** (default scope): Enabled,
Categories per Store View, Products per Store View, Also Warm, Warm a Page After It Is Saved, Pause
Between Requests, Wait While the Load Is Above.

## Tests

`Test/Unit`: the Varnish request (Host, scheme, ports), the queue (once per
page, the cap, full), outcomes, paths kept once in order, the Also Warm
setting cleaned. Measured on BEAM/ROOM, time to first byte: locally
(built-in cache) cold 0.1–1.0 s (home 0.38 s), warm 37–88 ms, 73 pages warmed
in 30 s; on production (Varnish, m7i.large) a page Magento builds takes
0.5–0.7 s the first time, 0.2 s with its block caches warm, 0.09–0.11 s from
Varnish (most of it the network from Europe to us-east-1).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
