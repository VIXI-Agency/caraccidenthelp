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

    /**
     * Whitelist of values accepted in the optional `source` field of a /lead
     * POST. Anything else falls back to 'unknown'. Used by the admin Leads
     * page filter and the analytics breakdown.
     *
     * Source semantics:
     *   path_a_html_v1                - submitted by HTML v1 form (tracking.js)
     *   path_b_growform               - submitted from /thank-you/ via path-b.js with valid cookie + lead_stage
     *   path_b_no_cookie              - thank-you hit without cah_variant_<id> cookie (direct visit / cookie expired)
     *   path_b_parse_failed_no_dot    - cookie present but malformed (no '.')
     *   path_b_parse_failed_no_ids    - cookie parts didn't yield valid ids
     *   path_b_missing_stage          - cookie OK but ?lead_stage param missing/invalid (still capture)
     *   unknown                       - source not provided or not recognized
     */
    public const ALLOWED_SOURCES = [
        'path_a_html_v1',
        'path_b_growform',
        'path_b_no_cookie',
        'path_b_parse_failed_no_dot',
        'path_b_parse_failed_no_ids',
        'path_b_missing_stage',
        'unknown',
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly LeadsRepository $leads,
        private readonly PageviewsRepository $pageviews,
        private readonly LeadStage $leadStage,
        private readonly LeadPayloadParser $parser,
        private readonly MakeForwarder $forwarder,
        private readonly ?Logger $logger = null,
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

        \register_rest_route(self::NAMESPACE, '/lead-skip', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handleLeadSkip'],
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
            $this->logger?->warn('rest.pageview.400', 'missing identifiers', [
                'test_id'      => $testId,
                'variant_id'   => $variantId,
                'visitor_id'   => $visitorId,
                'content_type' => (string) $request->get_header('content-type'),
                'body_preview' => $this->bodyPreview($request),
                'ip_hash'      => $this->ipHash(),
                'user_agent'   => $this->truncate((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 200),
                'referer'      => $this->truncate((string) ($_SERVER['HTTP_REFERER'] ?? ''), 200),
            ]);
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

        $this->logger?->info('rest.pageview.received', 'pageview tracked', [
            'test_id'    => $testId,
            'variant_id' => $variantId,
            'visitor_id' => $visitorId,
            'utm_source' => $this->str($body, 'utm_source'),
            'utm_campaign' => $this->str($body, 'utm_campaign'),
            'referrer'   => $this->truncate($this->str($body, 'referrer'), 200),
            'path'       => $this->str($body, 'path'),
            'user_agent' => $this->truncate((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 200),
            'ip_hash'    => $this->ipHash(),
        ]);

        return new WP_REST_Response(['success' => true], 201);
    }

    public function handleLead(WP_REST_Request $request): WP_REST_Response
    {
        $body = $this->readBody($request);

        // skip_make: when true, register the lead in the dashboard but do NOT
        // forward to Make.com. Use this for variants where another system
        // (e.g., Growform) already submits the lead to Make directly, so the
        // plugin only needs to track the conversion for A/B test stats.
        $skipMake = !empty($body['skip_make']);

        $makePayload = $body['make_payload'] ?? null;

        // Log every /lead hit at the entry point, BEFORE any validation, so the
        // admin Logs page shows the true count of inbound POSTs. This is the
        // ground truth we'll use to compare against Hyros: every POST that
        // physically reached the endpoint will produce a log row, even if it
        // gets rejected below.
        $this->logger?->info('rest.lead.received', $skipMake ? 'lead received (skip_make)' : 'lead received', [
            'test_id'         => isset($body['test_id'])    ? (int) $body['test_id']    : null,
            'variant_id'      => isset($body['variant_id']) ? (int) $body['variant_id'] : null,
            'visitor_id'      => isset($body['visitor_id']) ? (string) $body['visitor_id'] : null,
            'skip_make'       => $skipMake,
            'has_make_payload'=> \is_array($makePayload) && !empty($makePayload),
            'has_form_meta'   => isset($body['form_meta']) && \is_array($body['form_meta']),
            'has_fields'      => isset($body['fields']) && \is_array($body['fields']),
            'content_type'    => (string) $request->get_header('content-type'),
            'ip_hash'         => $this->ipHash(),
            'user_agent'      => $this->truncate((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 200),
            'referer'         => $this->truncate((string) ($_SERVER['HTTP_REFERER'] ?? ''), 200),
        ]);

        // When skip_make is set, make_payload is optional. Otherwise it's required
        // because we need it to forward to Make and to parse lead fields.
        if (!$skipMake && !\is_array($makePayload)) {
            $this->logger?->warn('rest.lead.400', 'make_payload required but missing/invalid', [
                'skip_make'      => $skipMake,
                'body_keys'      => \array_keys($body),
                'body_preview'   => $this->bodyPreview($request),
                'content_type'   => (string) $request->get_header('content-type'),
                'ip_hash'        => $this->ipHash(),
                'user_agent'     => $this->truncate((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 200),
                'referer'        => $this->truncate((string) ($_SERVER['HTTP_REFERER'] ?? ''), 200),
            ]);
            return new WP_REST_Response(['success' => false, 'message' => 'make_payload is required'], 400);
        }

        // Parse lead fields. When skip_make is set with no make_payload, fall back
        // to form_meta.fields or top-level fields so the dashboard still gets data.
        if (\is_array($makePayload) && !empty($makePayload)) {
            $fields = $this->parser->parse($makePayload);
        } else {
            $fallbackFields = [];
            if (isset($body['form_meta']['fields']) && \is_array($body['form_meta']['fields'])) {
                $fallbackFields = $body['form_meta']['fields'];
            } elseif (isset($body['fields']) && \is_array($body['fields'])) {
                $fallbackFields = $body['fields'];
            }
            // Build a minimal make_payload-shaped array for the parser.
            $syntheticPayload = [[
                'event_type' => 'form_submission',
                'webhook'    => ['version' => '4'],
                'form_submission' => [
                    'submitted_at' => \current_time('c'),
                    'fields'       => $fallbackFields,
                    'lead_stage'   => $body['form_meta']['lead_stage'] ?? ($body['lead_stage'] ?? null),
                ],
                'form_meta' => $body['form_meta'] ?? [],
            ]];
            $fields = $this->parser->parse($syntheticPayload);
        }

        $stage  = $this->leadStage->compute($fields);
        // v1.0.22: pass $fields so the disqualified split (no-injury → /diminished-value-claim/,
        // everything else → /finished/) mirrors Growform's official redirect waterfall.
        $redirect = $this->leadStage->redirectUrl($stage, $fields);

        $rawPayload = \wp_json_encode($body);

        $sourceRaw = isset($body['source']) ? \sanitize_key((string) $body['source']) : '';
        $source = \in_array($sourceRaw, self::ALLOWED_SOURCES, true)
            ? $sourceRaw
            : ($sourceRaw === '' ? null : 'unknown');

        $data = \array_merge($fields, [
            'test_id'     => isset($body['test_id']) ? (int) $body['test_id'] : null,
            'variant_id'  => isset($body['variant_id']) ? (int) $body['variant_id'] : null,
            'visitor_id'  => isset($body['visitor_id'])
                ? \sanitize_text_field((string) $body['visitor_id'])
                : null,
            'lead_stage'  => $stage,
            'source'      => $source,
            'ip_hash'     => $this->ipHash(),
            'user_agent'  => $this->truncate((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 500),
            'raw_payload' => $rawPayload,
        ]);

        // v1.0.23: backend dedupe. With sendBeacon now in path-b.js, the same
        // submission may arrive twice (fetch + beacon) when the user closes the
        // tab while fetch is in-flight. Suppress the duplicate at the DB layer
        // by looking up any lead with the same email/phone + visitor_id within
        // the last 5 minutes. Returns the existing lead_id so the client still
        // gets a 201 with the redirect.
        $dupId = $this->leads->findRecentDuplicate(
            (string) ($data['email'] ?? ''),
            (string) ($data['phone'] ?? ''),
            (string) ($data['visitor_id'] ?? ''),
            300
        );
        if ($dupId > 0) {
            $this->logger?->info('rest.lead.dedupe', 'duplicate suppressed', [
                'existing_lead_id' => $dupId,
                'test_id'    => $data['test_id']    ?? null,
                'variant_id' => $data['variant_id'] ?? null,
                'visitor_id' => $data['visitor_id'] ?? null,
                'lead_stage' => $stage,
                'email'      => $data['email']      ?? null,
                'source'     => $source,
            ]);
            return new WP_REST_Response([
                'success'      => true,
                'lead_id'      => $dupId,
                'lead_stage'   => $stage,
                'redirect_url' => $redirect,
                'deduped'      => true,
            ], 200);
        }

        try {
            $leadId = $this->leads->create($data);
        } catch (\Throwable $e) {
            $this->logger?->error('rest.lead.500', 'lead insert threw', [
                'exception'  => $e->getMessage(),
                'test_id'    => $data['test_id']    ?? null,
                'variant_id' => $data['variant_id'] ?? null,
                'visitor_id' => $data['visitor_id'] ?? null,
                'lead_stage' => $stage,
                'email'      => $data['email']      ?? null,
                'phone'      => $data['phone']      ?? null,
                'ip_hash'    => $this->ipHash(),
            ]);
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Lead could not be saved.',
            ], 500);
        }

        if ($skipMake) {
            // Mark as skipped immediately so the row doesn't sit in pending forever
            // and the cron retry job doesn't try to forward it.
            try {
                $this->leads->markForwardSkipped($leadId, 'Client sent skip_make=true (e.g., Growform handles Make directly).');
            } catch (\Throwable $e) {
                $this->logger?->error('rest.lead.skipped.fail', 'markForwardSkipped threw', [
                    'lead_id'   => $leadId,
                    'exception' => $e->getMessage(),
                ]);
            }
        } else {
            // Forward to Make.com in BLOCKING mode so make_status is updated correctly.
            // Non-blocking mode previously returned true without ever calling
            // markForwardSuccess() / markForwardFailed(), leaving every lead stuck at
            // make_status=pending indefinitely. Blocking adds ~1-3s latency but is the
            // only way MakeForwarder reads the response and updates status.
            try {
                $this->forwarder->forward($leadId, $makePayload, true);
            } catch (\Throwable $e) {
                $this->logger?->error('rest.lead.forward.fail', 'forward dispatch threw', [
                    'lead_id'   => $leadId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->logger?->info('rest.lead.created', 'lead persisted', [
            'lead_id'      => $leadId,
            'test_id'      => $data['test_id']    ?? null,
            'variant_id'   => $data['variant_id'] ?? null,
            'visitor_id'   => $data['visitor_id'] ?? null,
            'lead_stage'   => $stage,
            'source'       => $source,
            'skip_make'    => $skipMake,
            'has_email'    => !empty($data['email']),
            'has_phone'    => !empty($data['phone']),
            'service_type' => $data['service_type'] ?? null,
        ]);

        return new WP_REST_Response([
            'success'      => true,
            'lead_id'      => $leadId,
            'lead_stage'   => $stage,
            'redirect_url' => $redirect,
            'make_status'  => $skipMake ? LeadsRepository::MAKE_STATUS_SKIPPED : null,
        ], 201);
    }

    /**
     * Observability-only endpoint. The /thank-you/ and /diminished-value-claim/
     * scripts (Path B) have several silent-return paths today (no cookie,
     * cookie parse failed, lead_stage missing, sessionStorage dedup hit, etc).
     * Each of those is a potential lost lead vs Hyros and we have no way to
     * see how often they fire. From v1.0.16 the snippet calls /lead-skip
     * BEFORE every silent return with a reason tag. This endpoint:
     *   - never validates auth (it's anonymous)
     *   - never rejects (returns 200 always)
     *   - logs to wp_cah_log under `rest.lead_skip.<reason>` so the existing
     *     admin Logs page surfaces the counts as filterable source pills
     */
    public function handleLeadSkip(WP_REST_Request $request): WP_REST_Response
    {
        $body = $this->readBody($request);

        $allowed = [
            'no-cookie',
            'parse-failed-no-dot',
            'parse-failed-no-ids',
            'from-cah-form',
            'missing-stage',
            'dedup-session',
            'fetch-failed',
        ];
        $reason = isset($body['reason']) ? \sanitize_key((string) $body['reason']) : '';
        if (!\in_array($reason, $allowed, true)) {
            $reason = 'unknown';
        }

        $this->logger?->info('rest.lead_skip.' . $reason, 'lead skipped client-side', [
            'reason'         => $reason,
            'test_id'        => isset($body['test_id'])    ? (int) $body['test_id']    : null,
            'variant_id'     => isset($body['variant_id']) ? (int) $body['variant_id'] : null,
            'visitor_id'     => isset($body['visitor_id']) ? (string) $body['visitor_id'] : null,
            'lead_stage'     => isset($body['lead_stage']) ? \sanitize_text_field((string) $body['lead_stage']) : null,
            'lead_stage_received' => isset($body['lead_stage_received']) ? \sanitize_text_field((string) $body['lead_stage_received']) : null,
            'from_cah_form'  => isset($body['from_cah_form']) ? (string) $body['from_cah_form'] : null,
            'has_email'      => !empty($body['has_email']),
            'has_phone'      => !empty($body['has_phone']),
            'url'            => $this->truncate(isset($body['url']) ? (string) $body['url'] : null, 200),
            'referrer'       => $this->truncate(isset($body['referrer']) ? (string) $body['referrer'] : null, 200),
            'cookie_raw'     => $this->truncate(isset($body['cookie_raw']) ? (string) $body['cookie_raw'] : null, 100),
            'content_type'   => (string) $request->get_header('content-type'),
            'ip_hash'        => $this->ipHash(),
            'user_agent'     => $this->truncate((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 200),
            'page_referrer'  => $this->truncate((string) ($_SERVER['HTTP_REFERER'] ?? ''), 200),
        ]);

        return new WP_REST_Response(['success' => true, 'reason' => $reason], 200);
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

    /**
     * Capture the first 500 chars of the raw request body so admin Logs can
     * see why a request was rejected without re-parsing. Safe to log even on
     * malformed JSON because we treat it as opaque text.
     */
    private function bodyPreview(WP_REST_Request $request): string
    {
        $raw = (string) $request->get_body();
        return \substr($raw, 0, 500);
    }
}
