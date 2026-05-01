<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

use VIXI\CahSplit\Repositories\LogsRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin-internal logger that dual-writes to wp_cah_log AND PHP error_log.
 *
 * Existed before only as scattered error_log() calls, which on Hostinger are
 * frequently disabled or routed to /dev/null and were impossible for non-shell
 * users to inspect. The DB-backed log is paired with an admin "Logs" page so
 * the issue can be diagnosed without server access.
 *
 * Levels: info / warn / error. Source is a short tag like 'rest.lead' so the
 * admin can filter quickly. Context is JSON-serialized; large fields are
 * truncated to keep individual rows reasonable.
 */
final class Logger
{
    public const LEVEL_INFO  = 'info';
    public const LEVEL_WARN  = 'warn';
    public const LEVEL_ERROR = 'error';

    private const MAX_MESSAGE_LEN = 500;
    private const MAX_CONTEXT_LEN = 20000;

    public function __construct(private readonly LogsRepository $logs)
    {
    }

    public function info(string $source, string $message, array $context = []): void
    {
        $this->write(self::LEVEL_INFO, $source, $message, $context);
    }

    public function warn(string $source, string $message, array $context = []): void
    {
        $this->write(self::LEVEL_WARN, $source, $message, $context);
    }

    public function error(string $source, string $message, array $context = []): void
    {
        $this->write(self::LEVEL_ERROR, $source, $message, $context);
    }

    private function write(string $level, string $source, string $message, array $context): void
    {
        $source  = \substr($source, 0, 64);
        $message = \substr($message, 0, self::MAX_MESSAGE_LEN);

        $contextJson = null;
        if ($context !== []) {
            $encoded = \wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (\is_string($encoded)) {
                $contextJson = \substr($encoded, 0, self::MAX_CONTEXT_LEN);
            }
        }

        try {
            $this->logs->insert($level, $source, $message, $contextJson);
        } catch (\Throwable $e) {
            // Never let a logger failure break the request. Fall through to
            // error_log only.
            \error_log('[cah-split] logger DB write failed: ' . $e->getMessage());
        }

        // Always also emit to PHP error_log so anyone with shell access still
        // gets it. Hostinger may discard this; the DB row is the canonical
        // record.
        $line = \sprintf(
            '[cah-split] [%s] %s — %s',
            $level,
            $source,
            $message
        );
        if ($contextJson !== null && $contextJson !== '') {
            $line .= ' | ctx=' . \substr($contextJson, 0, 1000);
        }
        \error_log($line);
    }
}
