<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

use VIXI\CahSplit\Admin\Admin;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?Plugin $instance = null;

    public readonly Settings $settings;
    public readonly Admin $admin;

    private function __construct()
    {
        $this->settings = new Settings();
        $this->admin    = new Admin($this->settings);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        \load_plugin_textdomain(
            'cah-split',
            false,
            \dirname(\plugin_basename(CAH_SPLIT_PLUGIN_FILE)) . '/languages'
        );

        if (\is_admin()) {
            $this->admin->boot();
        }
    }
}
