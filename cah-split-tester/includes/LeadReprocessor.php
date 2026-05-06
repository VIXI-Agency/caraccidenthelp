<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

use VIXI\CahSplit\Repositories\LeadsRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Re-parse leads currently stored as lead_stage='unknown' by reading their
 * raw_payload column and running it through the current LeadPayloadParser +
 * LeadStage::compute(). Used to recover historical rows that were saved
 * before a parser fix shipped (e.g. v1.0.7 / v1.0.9 changes).
 *
 * Idempotent: if a row was already correctly classified it ends up with the
 * same stage. Safe to run repeatedly.
 */
final class LeadReprocessor
{
    public function __construct(
        private readonly LeadsRepository $leads,
        private readonly LeadPayloadParser $parser,
        private readonly LeadStage $leadStage,
    ) {
    }

    /**
     * Reprocess every lead with stage='unknown' for the given test.
     *
     * @return array{
     *   scanned: int,
     *   updated: int,
     *   qualified: int,
     *   disqualified: int,
     *   still_unknown: int,
     *   skipped: int,
     *   errors: int
     * }
     */
    public function reprocessTest(int $testId, int $limit = 500): array
    {
        $rows = $this->leads->findUnknownByTestId($testId, $limit);
        return $this->reprocessRows($rows);
    }

    /**
     * Reprocess every lead with payload for the given test, regardless of
     * current stage. Use after business-rule changes (e.g. service whitelist).
     *
     * @return array{
     *   scanned: int,
     *   updated: int,
     *   qualified: int,
     *   disqualified: int,
     *   still_unknown: int,
     *   skipped: int,
     *   errors: int
     * }
     */
    public function reprocessAllForTest(int $testId, int $limit = 5000): array
    {
        $rows = $this->leads->findWithPayloadByTestId($testId, $limit);
        return $this->reprocessRows($rows);
    }

    /**
     * @param array<int,array{id:mixed,raw_payload:mixed}> $rows
     * @return array{
     *   scanned: int,
     *   updated: int,
     *   qualified: int,
     *   disqualified: int,
     *   still_unknown: int,
     *   skipped: int,
     *   errors: int
     * }
     */
    private function reprocessRows(array $rows): array
    {

        $stats = [
            'scanned'       => 0,
            'updated'       => 0,
            'qualified'     => 0,
            'disqualified'  => 0,
            'still_unknown' => 0,
            'skipped'       => 0,
            'errors'        => 0,
        ];

        foreach ($rows as $row) {
            $stats['scanned']++;
            $id  = (int) $row['id'];
            $raw = (string) ($row['raw_payload'] ?? '');

            if ($raw === '') {
                $stats['skipped']++;
                continue;
            }

            $body = \json_decode($raw, true);
            if (!\is_array($body)) {
                $stats['skipped']++;
                continue;
            }

            try {
                $fields = $this->parseFromBody($body);
                $stage  = $this->leadStage->compute($fields);

                $ok = $this->leads->updateParsedFields($id, $fields, $stage);
                if ($ok) {
                    $stats['updated']++;
                    if ($stage === LeadStage::STAGE_QUALIFIED) {
                        $stats['qualified']++;
                    } elseif ($stage === LeadStage::STAGE_DISQUALIFIED) {
                        $stats['disqualified']++;
                    } else {
                        $stats['still_unknown']++;
                    }
                } else {
                    $stats['errors']++;
                }
            } catch (\Throwable $e) {
                \error_log('[cah-split] reprocess lead ' . $id . ' failed: ' . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Mirror RestApi::handleLead() body-shape handling so we parse the same
     * way the live ingestion does. Supports both classic make_payload shape
     * and skip_make / fallback fields shape.
     */
    private function parseFromBody(array $body): array
    {
        $makePayload = $body['make_payload'] ?? null;

        if (\is_array($makePayload) && !empty($makePayload)) {
            return $this->parser->parse($makePayload);
        }

        $fallbackFields = [];
        if (isset($body['form_meta']['fields']) && \is_array($body['form_meta']['fields'])) {
            $fallbackFields = $body['form_meta']['fields'];
        } elseif (isset($body['fields']) && \is_array($body['fields'])) {
            $fallbackFields = $body['fields'];
        }

        $synthetic = [[
            'event_type' => 'form_submission',
            'webhook'    => ['version' => '4'],
            'form_submission' => [
                'submitted_at' => \current_time('c'),
                'fields'       => $fallbackFields,
            ],
            'form_meta' => $body['form_meta'] ?? [],
        ]];

        return $this->parser->parse($synthetic);
    }
}
