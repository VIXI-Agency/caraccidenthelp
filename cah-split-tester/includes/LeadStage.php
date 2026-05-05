<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

if (!defined('ABSPATH')) {
    exit;
}

final class LeadStage
{
    public const STAGE_QUALIFIED    = 'qualified';
    public const STAGE_DISQUALIFIED = 'disqualified';
    public const STAGE_UNKNOWN      = 'unknown';

    public const URL_QUALIFIED              = 'https://caraccidenthelp.net/thank-you/?lead_stage=qualified-lead&from_cah_form=1';
    public const URL_DISQUALIFIED_NO_INJURY = 'https://caraccidenthelp.net/diminished-value-claim/?lead_stage=disqualified-lead&from_cah_form=1';
    public const URL_DISQUALIFIED_OTHER     = 'https://caraccidenthelp.net/finished/?lead_stage=disqualified-lead&from_cah_form=1';

    /**
     * Backwards-compat alias for code paths that still reference the old
     * single-disqualified URL. Prefer URL_DISQUALIFIED_NO_INJURY /
     * URL_DISQUALIFIED_OTHER plus redirectUrl() going forward.
     */
    public const URL_DISQUALIFIED           = self::URL_DISQUALIFIED_NO_INJURY;

    /**
     * Timeframes that DO NOT disqualify the lead. Anything outside this list
     * (e.g. 'within_2_year', 'longer_than_2_year') marks the lead as
     * disqualified.
     */
    private const QUALIFIED_TIMEFRAMES = [
        'within_1_week',
        'within_1_3_months',
        'within_4_6_months',
        'within_1_year',
    ];

    /**
     * Service types that CAN qualify per the upstream Growform rules confirmed
     * by the client (Kaleb): only Car / Motorcycle / Trucking accidents are
     * eligible. Every other service_type (bicycle, pedestrian, work, other,
     * etc.) is automatically disqualified regardless of the answers to
     * attorney/fault/injury/timeframe.
     *
     * NOTE (v1.0.22 — REVERSAL of v1.0.21): the v1.0.21 release removed this
     * whitelist based on an earlier (wrong) understanding that service_type
     * was not a disqualifier. The Growform UI screenshots and a direct
     * statement from the client confirm the original behaviour was correct,
     * so the whitelist is restored here.
     */
    public const QUALIFIED_SERVICES = [
        'car_accident',
        'motorcycle_accident',
        'trucking_accident',
    ];

    public function compute(array $fields): string
    {
        $required = ['service_type', 'attorney', 'fault', 'injury', 'timeframe'];
        foreach ($required as $key) {
            if (empty($fields[$key])) {
                return self::STAGE_UNKNOWN;
            }
        }

        $qualified = (
            \in_array($fields['service_type'], self::QUALIFIED_SERVICES, true)
            && $fields['attorney']  === 'not_yet'
            && $fields['fault']     === 'no'
            && $fields['injury']    === 'yes'
            && \in_array($fields['timeframe'], self::QUALIFIED_TIMEFRAMES, true)
        );

        return $qualified ? self::STAGE_QUALIFIED : self::STAGE_DISQUALIFIED;
    }

    /**
     * Resolve the post-submit redirect URL.
     *
     * Mirrors Growform's official waterfall (per client-supplied screenshots):
     *   1. qualified                     → /thank-you/
     *   2. disqualified AND injury=No    → /diminished-value-claim/
     *   3. disqualified (everything else)→ /finished/
     *
     * The optional $fields argument lets callers (RestApi) drive the
     * disqualified split; without it the legacy single URL is returned for
     * backwards compatibility.
     */
    public function redirectUrl(string $stage, array $fields = []): ?string
    {
        if ($stage === self::STAGE_QUALIFIED) {
            return self::URL_QUALIFIED;
        }

        if ($stage === self::STAGE_DISQUALIFIED) {
            $injury = $fields['injury'] ?? null;
            return $injury === 'no'
                ? self::URL_DISQUALIFIED_NO_INJURY
                : self::URL_DISQUALIFIED_OTHER;
        }

        return null;
    }
}
