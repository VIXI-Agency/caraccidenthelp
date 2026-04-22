<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/cah-split-tester.php';

\VIXI\CahSplit\Uninstaller::run();
