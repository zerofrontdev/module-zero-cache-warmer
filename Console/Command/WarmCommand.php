<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\CacheWarmer\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Zero\CacheWarmer\Model\Config;
use Zero\CacheWarmer\Model\Queue;
use Zero\CacheWarmer\Model\Warmer;

/**
 * bin/magento zero:cache:warm [--store=code,…] [--products=N] [--later] [--dry-run]
 *
 * Warms the full page cache now, or with --later leaves it to the next cron
 * run (what a deploy does: the deploy doesn't wait for it).
 */
class WarmCommand extends Command
{
    public function __construct(
        private readonly Warmer $warmer,
        private readonly Queue $queue,
        private readonly Config $config,
        private readonly State $state
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('zero:cache:warm')
            ->setDescription('Requests the most visited pages of each store view so the full page cache holds them')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view codes, comma-separated (default: every active store view)')
            ->addOption('products', null, InputOption::VALUE_REQUIRED, 'Product pages per store view (default: the setting)')
            ->addOption('later', null, InputOption::VALUE_NONE, 'Leave it to the next cron run and return at once')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the URLs');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('later')) {
            $this->queue->full();
            $output->writeln($this->config->isEnabled()
                ? '<info>Queued: the next cron run warms every store view.</info>'
                : '<comment>Queued, but the warmer is off (Stores > Configuration > Zero > Cache Warmer).</comment>');

            return Command::SUCCESS;
        }
        try {
            $this->state->setAreaCode(Area::AREA_FRONTEND);
        } catch (LocalizedException) {
            // Already set.
        }
        $stores = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('store')))));
        $products = $input->getOption('products') !== null ? max(0, (int) $input->getOption('products')) : $this->config->products();
        if ($input->getOption('dry-run')) {
            foreach ($this->warmer->plan($stores, $products) as $url) {
                $output->writeln($url);
            }

            return Command::SUCCESS;
        }
        $stats = $this->warmer->warmAll($stores, $products, static function (string $url, int $status, float $ms) use ($output): void {
            if ($output->isVerbose() || $status < 200 || $status >= 300) {
                $output->writeln(sprintf('%3d %6.0f ms  %s', $status, $ms, $url));
            }
        });
        if ($stats === null) {
            $output->writeln('<error>Another warm is running (the cron, or this command elsewhere).</error>');

            return Command::FAILURE;
        }
        $output->writeln('<info>' . Warmer::summary($stats) . '.</info>');

        return Command::SUCCESS;
    }
}
