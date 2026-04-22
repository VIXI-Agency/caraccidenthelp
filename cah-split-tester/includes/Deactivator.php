<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

if (!defined('ABSPATH')) {
    exit;
}

final class Deactivator
{
    public static function deactivate(): void
    {
        // Intentional no-op in Phase 1. Cron unscheduling and transient cleanup land with later phases.
    }
}
