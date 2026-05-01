<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

use VIXI\CahSplit\Repositories\LeadsRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class MakeForwarder
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly Settings $settings,
        private readonly LeadsRepository $leads,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function forward(int $leadId, array $makePayload, bool $blocking = false): bool
    {
        $url = $this->settings->makeWebhookUrl();
        if ($url === '') {
            $this->leads->markForwardFailed($leadId, 'No Make.com webhook URL configured.');
            $this->logger?->error('make.forward.no_url', 'webhook URL not configured', ['lead_id' => $leadId]);
            return false;
        }

        $payload = $this->stampLeadId($makePayload, $leadId);

        $response = \wp_remote_post($url, [
            'method'   => 'POST',
            'timeout'  => $blocking ? 10 : 5,
            'blocking' => $blocking,
            'headers'  => ['Content-Type' => 'application/json'],
            'body'     => \wp_json_encode($payload),
        ]);

        if (!$blocking) {
            return true;
        }

        if (\is_wp_error($response)) {
            $msg = $response->get_error_message();
            $this->leads->markForwardFailed($leadId, $msg);
            $this->logger?->error('make.forward.wp_error', 'wp_remote_post returned WP_Error', [
                'lead_id' => $leadId,
                'error'   => $msg,
            ]);
            return false;
        }

        $code = (int) \wp_remote_retrieve_response_code($response);
        $body = (string) \wp_remote_retrieve_body($response);

        if ($code >= 200 && $code < 300) {
            $this->leads->markForwardSuccess($leadId, $body !== '' ? \substr($body, 0, 5000) : null);
            $this->logger?->info('make.forward.ok', 'forwarded to Make.com', [
                'lead_id'  => $leadId,
                'http'     => $code,
                'response' => \substr($body, 0, 200),
            ]);
            return true;
        }

        $this->leads->markForwardFailed($leadId, \sprintf('HTTP %d: %s', $code, \substr($body, 0, 4000)));
        $this->logger?->error('make.forward.non2xx', \sprintf('non-2xx response (HTTP %d)', $code), [
            'lead_id'  => $leadId,
            'http'     => $code,
            'response' => \substr($body, 0, 500),
        ]);
        return false;
    }

    public function retryPending(): void
    {
        $rows = $this->leads->findRetryable(self::MAX_ATTEMPTS);
        foreach ($rows as $row) {
            $raw = $row['raw_payload'] ?? null;
            if (!\is_string($raw) || $raw === '') {
                $this->leads->markForwardFailed((int) $row['id'], 'Raw payload missing; cannot retry.');
                continue;
            }
            $decoded = \json_decode($raw, true);
            if (!\is_array($decoded)) {
                $this->leads->markForwardFailed((int) $row['id'], 'Raw payload could not be decoded.');
                continue;
            }
            $makePayload = $decoded['make_payload'] ?? null;
            if (!\is_array($makePayload)) {
                $this->leads->markForwardFailed((int) $row['id'], 'Raw payload missing make_payload section.');
                continue;
            }
            $this->forward((int) $row['id'], $makePayload, true);
        }
    }

    private function stampLeadId(array $makePayload, int $leadId): array
    {
        if (!isset($makePayload[0]) || !\is_array($makePayload[0])) {
            return $makePayload;
        }
        if (!isset($makePayload[0]['form_meta']) || !\is_array($makePayload[0]['form_meta'])) {
            $makePayload[0]['form_meta'] = [];
        }
        $makePayload[0]['form_meta']['cah_lead_id'] = $leadId;
        return $makePayload;
    }
}
