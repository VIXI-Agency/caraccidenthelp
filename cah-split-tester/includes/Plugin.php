<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

use VIXI\CahSplit\Admin\Admin;
use VIXI\CahSplit\Repositories\LeadsRepository;
use VIXI\CahSplit\Repositories\LogsRepository;
use VIXI\CahSplit\Repositories\PageviewsRepository;
use VIXI\CahSplit\Repositories\StatsRepository;
use VIXI\CahSplit\Repositories\TestsRepository;
use VIXI\CahSplit\Repositories\VariantsRepository;
use VIXI\CahSplit\Stats\Significance;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?Plugin $instance = null;

    public readonly Settings $settings;
    public readonly TestsRepository $tests;
    public readonly VariantsRepository $variants;
    public readonly LeadsRepository $leads;
    public readonly PageviewsRepository $pageviews;
    public readonly LogsRepository $logsRepo;
    public readonly Logger $logger;
    public readonly StatsRepository $stats;
    public readonly Significance $significance;
    public readonly LeadStage $leadStage;
    public readonly LeadPayloadParser $parser;
    public readonly MakeForwarder $forwarder;
    public readonly VariantRenderer $variantRenderer;
    public readonly Router $router;
    public readonly RestApi $restApi;
    public readonly Cron $cron;
    public readonly LeadReprocessor $reprocessor;
    public readonly Admin $admin;

    private function __construct()
    {
        $this->settings        = new Settings();
        $this->tests           = new TestsRepository();
        $this->variants        = new VariantsRepository();
        $this->logsRepo        = new LogsRepository();
        $this->logger          = new Logger($this->logsRepo);
        $this->leads           = new LeadsRepository($this->logger);
        $this->pageviews       = new PageviewsRepository();
        $this->stats           = new StatsRepository($this->settings);
        $this->significance    = new Significance();
        $this->leadStage       = new LeadStage();
        $this->parser          = new LeadPayloadParser();
        $this->forwarder       = new MakeForwarder($this->settings, $this->leads, $this->logger);
        $this->variantRenderer = new VariantRenderer($this->settings);
        $this->router          = new Router($this->tests, $this->variants, $this->settings, $this->variantRenderer, $this->logger);
        $this->restApi         = new RestApi(
            $this->settings,
            $this->leads,
            $this->pageviews,
            $this->leadStage,
            $this->parser,
            $this->forwarder,
            $this->logger,
        );
        $this->cron            = new Cron($this->forwarder, $this->logsRepo);
        $this->reprocessor     = new LeadReprocessor(
            $this->leads,
            $this->parser,
            $this->leadStage,
        );
        $this->admin           = new Admin(
            $this->settings,
            $this->tests,
            $this->variants,
            $this->leads,
            $this->pageviews,
            $this->stats,
            $this->significance,
            $this->forwarder,
            $this->reprocessor,
            $this->logsRepo,
        );
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
        $this->cron->boot();

        if (\is_admin()) {
            $this->admin->boot();
        }
    }
}
