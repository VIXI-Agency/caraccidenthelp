<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

use VIXI\CahSplit\Repositories\LeadsRepository;
use VIXI\CahSplit\Repositories\PageviewsRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class RestApi
{
    public const NAMESPACE = 'cah-split/v1';

    public function __construct(
        private readonly Settings $settings,
        private readonly LeadsRepository $leads,
        private readonly PageviewsRepository $pageviews,
        private readonly LeadStage $leadStage,
        private readonly LeadPayloadParser $parser,
        private readonly MakeForwarder $forwarder,
    ) {
    }

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
        $body = $this->readBody($request);

        $testId    = isset($body['test_id']) ? (int) $body['test_id'] : 0;
        $variantId = isset($body['variant_id']) ? (int) $body['variant_id'] : 0;
        $visitorId = isset($body['visitor_id']) ? \sanitize_text_field((string) $body['visitor_id']) : '';

        if ($testId <= 0 || $variantId <= 0 || $visitorId === '') {
            return new WP_REST_Response(['success' => false, 'message' => 'missing identifiers'], 400);
        }

        $this->pageviews->create([
            'test_id'     => $testId,
            'variant_id'  => $variantId,
            'visitor_id'  => $visitorId,
            'utm_source'  => $this->str($body, 'utm_source'),
            'utm_medium'  => $this->str($body, 'utm_medium'),
            'utm_campaign'=> $this->str($body, 'utm_campaign'),
            'utm_term'    => $this->str($body, 'utm_term'),
            'utm_content' => $this->str($body, 'utm_content'),
            'clickid'     => $this->str($body, 'clickid'),
            'referrer'    => $this->truncate($this->str($body, 'referrer'), 2048),
            'user_agent'  => $this->truncate((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 500),
            'ip_hash'     => $this->ipHash(),
        ]);

        return new WP_REST_Response(['success' => true], 201);
    }

    public function handleLead(WP_REST_Request $request): WP_REST_Response
    {
        $body = $this->readBody($request);

        $makePayload = $body['make_payload'] ?? null;
        if (!\is_array($makePayload)) {
            return new WP_REST_Response(['success' => false, 'message' => 'make_payload is required'], 400);
        }

        $fields = $this->parser->parse($makePayload);
        $stage  = $this->leadStage->compute($fields);
        $redirect = $this->leadStage->redirectUrl($stage);

        $rawPayload = \wp_json_encode($body);

        $data = \array_merge($fields, [
            'test_id'     => isset($body['test_id']) ? (int) $body['test_id'] : null,
            'variant_id'  => isset($body['variant_id']) ? (int) $body['variant_id'] : null,
            'visitor_id'  => isset($body['visitor_id'])
                ? \sanitize_text_field((string) $body['visitor_id'])
                : null,
            'lead_stage'  => $stage,
            'ip_hash'     => $this->ipHash(),
            'user_agent'  => $this->truncate((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 500),
            'raw_payload' => $rawPayload,
        ]);

        try {
            $leadId = $this->leads->create($data);
        } catch (\Throwable $e) {
            \error_log('[cah-split] Lead insert failed: ' . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Lead could not be saved.',
            ], 500);
        }

        // Forward to Make.com in BLOCKING mode so make_status is updated correctly.
        // Non-blocking mode previously returned true without ever calling
        // markForwardSuccess() / markForwardFailed(), leaving every lead stuck at
        // make_status=pending indefinitely. Blocking adds ~1-3s latency but is the
        // only way MakeForwarder reads the response and updates status.
        try {
            $this->forwarder->forward($leadId, $makePayload, true);
        } catch (\Throwable $e) {
            \error_log('[cah-split] Make forward dispatch failed: ' . $e->getMessage());
        }

        return new WP_REST_Response([
            'success'     => true,
            'lead_id'     => $leadId,
            'lead_stage'  => $stage,
            'redirect_url'=> $redirect,
        ], 201);
    }

    private function readBody(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();
        if (\is_array($body)) {
            return $body;
        }
        $raw = (string) $request->get_body();
        if ($raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);
        return \is_array($decoded) ? $decoded : [];
    }

    private function str(array $body, string $key): ?string
    {
        if (!isset($body[$key])) {
            return null;
        }
        $value = (string) $body[$key];
        return $value === '' ? null : \sanitize_text_field($value);
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        return \substr($value, 0, $max);
    }

    private function ipHash(): ?string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return null;
        }
        return \hash('sha256', $ip . '|' . $this->settings->ipHashSalt());
    }
}
