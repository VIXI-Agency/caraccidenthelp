<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class RestApi
{
    public const NAMESPACE = 'cah-split/v1';

    public function boot(): void
    {
        \add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        \register_rest_route(self::NAMESPACE, '/tracking.js', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handleTrackingJs'],
            'permission_callback' => '__return_true',
        ]);

        \register_rest_route(self::NAMESPACE, '/pageview', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handlePageview'],
            'permission_callback' => '__return_true',
        ]);

        \register_rest_route(self::NAMESPACE, '/lead', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handleLead'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handleTrackingJs(WP_REST_Request $request): void
    {
        $path = CAH_SPLIT_PLUGIN_DIR . 'assets/tracking.js';
        if (!\is_readable($path)) {
            \status_header(500);
            exit;
        }
        \nocache_headers();
        \header('Content-Type: application/javascript; charset=utf-8');
        \header('Cache-Control: public, max-age=300');
        echo \file_get_contents($path);
        exit;
    }

    public function handlePageview(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Not implemented yet — wiring lands in Phase 3.',
        ], 501);
    }

    public function handleLead(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Not implemented yet — wiring lands in Phase 3.',
        ], 501);
    }
}
