<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Zero\CacheWarmer\Model\Fetcher;

/**
 * Through Varnish the request keeps the page's Host and scheme (Varnish's
 * cache key: URL, Host, X-Forwarded-Proto); without it, the URL as it is.
 */
class FetcherTest extends TestCase
{
    public function testThroughVarnishTheHostAndSchemeTravelInHeaders(): void
    {
        [$url, $headers] = Fetcher::request('https://demo.zerofront.dev/beam/torches.html?p=2', 'http://127.0.0.1:6081');

        $this->assertSame('http://127.0.0.1:6081/beam/torches.html?p=2', $url);
        $this->assertSame('demo.zerofront.dev', $headers['Host']);
        $this->assertSame('https', $headers['X-Forwarded-Proto']);
        $this->assertSame(Fetcher::USER_AGENT, $headers['User-Agent']);
    }

    public function testANonDefaultPortStaysInTheHost(): void
    {
        [, $headers] = Fetcher::request('http://magento2.test:8080/', 'http://127.0.0.1:6081/');

        $this->assertSame('magento2.test:8080', $headers['Host']);
        $this->assertSame('http', $headers['X-Forwarded-Proto']);
    }

    public function testWithoutVarnishThePublicUrl(): void
    {
        [$url, $headers] = Fetcher::request('http://magento2.test:8080/men.html', null);

        $this->assertSame('http://magento2.test:8080/men.html', $url);
        $this->assertArrayNotHasKey('Host', $headers);
    }
}
