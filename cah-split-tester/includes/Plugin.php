<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

use VIXI\CahSplit\Admin\Admin;
use VIXI\CahSplit\Repositories\TestsRepository;
use VIXI\CahSplit\Repositories\VariantsRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?Plugin $instance = null;

    public readonly Settings $settings;
    public readonly TestsRepository $tests;
    public readonly VariantsRepository $variants;
    public readonly VariantRenderer $variantRenderer;
    public readonly Router $router;
    public readonly RestApi $restApi;
    public readonly Admin $admin;

    private function __construct()
    {
        $this->settings        = new Settings();
        $this->tests           = new TestsRepository();
        $this->variants        = new VariantsRepository();
        $this->variantRenderer = new VariantRenderer($this->settings);
        $this->router          = new Router($this->tests, $this->variants, $this->settings, $this->variantRenderer);
        $this->restApi         = new RestApi();
        $this->admin           = new Admin($this->settings, $this->tests, $this->variants);
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

        Activator::migrateIfNeeded();

        $this->router->boot();
        $this->restApi->boot();

        if (\is_admin()) {
            $this->admin->boot();
        }
    }
}
