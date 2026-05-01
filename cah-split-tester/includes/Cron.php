<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

use VIXI\CahSplit\Repositories\LogsRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class Cron
{
    public const RETRY_HOOK     = 'cah_split_retry_make_forwards';
    public const PRUNE_LOGS_HOOK = 'cah_split_prune_logs';

    public const LOG_RETENTION_DAYS = 14;

    public function __construct(
        private readonly MakeForwarder $forwarder,
        private readonly ?LogsRepository $logs = null,
    ) {
    }

    public function boot(): void
    {
        \add_action(self::RETRY_HOOK, [$this, 'run']);
        \add_action(self::PRUNE_LOGS_HOOK, [$this, 'pruneLogs']);
        \add_action('init', [$this, 'scheduleRetries']);
        \add_action('init', [$this, 'schedulePruneLogs']);
    }

    public function scheduleRetries(): void
    {
        if (!\wp_next_scheduled(self::RETRY_HOOK)) {
            \wp_schedule_event(\time() + 300, 'hourly', self::RETRY_HOOK);
        }
    }

    public function schedulePruneLogs(): void
    {
        if (!\wp_next_scheduled(self::PRUNE_LOGS_HOOK)) {
            \wp_schedule_event(\time() + 600, 'daily', self::PRUNE_LOGS_HOOK);
        }
    }

    public function run(): void
    {
        $this->forwarder->retryPending();
    }

    public function pruneLogs(): void
    {
        $this->logs?->pruneOlderThanDays(self::LOG_RETENTION_DAYS);
    }

    public static function unschedule(): void
    {
        foreach ([self::RETRY_HOOK, self::PRUNE_LOGS_HOOK] as $hook) {
            $next = \wp_next_scheduled($hook);
            if ($next !== false) {
                \wp_unschedule_event($next, $hook);
            }
        }
    }
}
