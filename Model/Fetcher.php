<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\PageCache\Model\Config as PageCacheConfig;

/**
 * Requests one page as a guest would, so the full page cache stores it.
 *
 * With Varnish the request goes to Varnish itself (the first of env.php's
 * `http_cache_hosts`, where Magento sends its purges) with the page's Host and
 * X-Forwarded-Proto: the same cache entry the public HTTPS server's request
 * would make, without CloudFront, TLS or the crawlers' speed limit in the way.
 * With Magento's own cache, the store's URL is requested as it is.
 */
class Fetcher
{
    public const USER_AGENT = 'ZeroCacheWarmer/1.0 (Magento full page cache warmer)';
    private const TIMEOUT = 30;
    /** A storefront that doesn't answer is known in seconds, not after the whole timeout per page. */
    private const CONNECT_TIMEOUT = 5;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly PageCacheConfig $pageCacheConfig,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /** Varnish's address (http://host:port), or null to request the public URL. */
    public function varnish(): ?string
    {
        if ((int) $this->pageCacheConfig->getType() !== PageCacheConfig::VARNISH) {
            return null;
        }
        $hosts = $this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_CACHE_HOSTS);
        if (!is_array($hosts) || empty($hosts[0]['host'])) {
            return null;
        }

        return 'http://' . $hosts[0]['host'] . ':' . (int) ($hosts[0]['port'] ?? 80);
    }

    /**
     * @return array{0: int, 1: float} the HTTP status (0: no answer) and the time in ms
     */
    public function fetch(string $url, ?string $varnish): array
    {
        [$target, $headers] = self::request($url, $varnish);
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        $curl->setHeaders($headers);
        $start = microtime(true);
        try {
            $curl->get($target);
            $status = (int) $curl->getStatus();
        } catch (\Throwable) {
            $status = 0;
        }

        return [$status, (microtime(true) - $start) * 1000];
    }

    /**
     * @return array{0: string, 1: array<string, string>} the URL to request and its headers
     */
    public static function request(string $url, ?string $varnish): array
    {
        $headers = ['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html', 'Accept-Encoding' => 'gzip'];
        if ($varnish === null) {
            return [$url, $headers];
        }
        $parts = parse_url($url);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        if (isset($parts['port']) && !in_array([(int) $parts['port'], $scheme], [[80, 'http'], [443, 'https']], true)) {
            $host .= ':' . $parts['port'];
        }
        $path = (string) ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return [rtrim($varnish, '/') . $path, $headers + ['Host' => $host, 'X-Forwarded-Proto' => $scheme]];
    }
}
