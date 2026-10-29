<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Stores > Configuration > Zero > Cache Warmer (default scope only: the warmer
 * runs once for the whole installation).
 */
class Config
{
    private const PATH = 'zero_cache_warmer/general/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH . 'enabled');
    }

    public function products(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::PATH . 'products'));
    }

    /**
     * Category pages per store view, the top levels first: a catalogue of 2 000 categories took an hour to warm.
     */
    public function categories(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::PATH . 'categories'));
    }

    /**
     * @return string[] paths under a store view's URL, without slashes at the ends
     */
    public function extraPaths(): array
    {
        return self::lines((string) $this->scopeConfig->getValue(self::PATH . 'extra_paths'));
    }

    public function warmAfterSave(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(self::PATH . 'after_save');
    }

    public function delayMs(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::PATH . 'delay_ms'));
    }

    /** The load average above which the warmer waits; 0 in the settings: the number of cores. */
    public function maxLoad(): float
    {
        return max(0.0, (float) $this->scopeConfig->getValue(self::PATH . 'max_load'));
    }

    /**
     * @return string[]
     */
    public static function lines(string $text): array
    {
        $paths = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim(trim($line), '/');
            if ($line !== '' && !str_contains($line, '..') && preg_match('#^[A-Za-z0-9_\-./]+$#', $line)) {
                $paths[] = $line;
            }
        }

        return array_values(array_unique($paths));
    }
}
