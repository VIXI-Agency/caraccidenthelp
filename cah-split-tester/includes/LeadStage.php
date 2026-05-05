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

    public const URL_QUALIFIED    = 'https://caraccidenthelp.net/thank-you/?lead_stage=qualified-lead&from_cah_form=1';
    public const URL_DISQUALIFIED = 'https://caraccidenthelp.net/diminished-value-claim/?lead_stage=disqualified-lead&from_cah_form=1';

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
     * NOTE (v1.0.21): service_type is NOT a disqualifier. Per business rules,
     * EVERY accident type (car, motorcycle, truck, bicycle/e-bike, pedestrian,
     * accident-or-injury-at-work, other accident) is potentially qualified.
     * Disqualification depends ONLY on attorney/fault/injury/timeframe.
     *
     * The previous implementation (v1.0.0 – v1.0.20) had a hardcoded
     * QUALIFIED_SERVICES = [car, motorcycle, trucking] whitelist here that
     * silently disqualified every other accident type at the server, for BOTH
     * Growform-fed Control AND HTML V1. This was the same bug the JS in
     * variants/v1.html had (fixed in v1.0.19), but the server-side variant
     * was even more impactful because it affected every variant including
     * Growform-fed ones, where the JS fix had no effect.
     */
    public function compute(array $fields): string
    {
        $required = ['attorney', 'fault', 'injury', 'timeframe'];
        foreach ($required as $key) {
            if (empty($fields[$key])) {
                return self::STAGE_UNKNOWN;
            }
        }

        $qualified = (
            $fields['attorney'] === 'not_yet'
            && $fields['fault']    === 'no'
            && $fields['injury']   === 'yes'
            && \in_array($fields['timeframe'], self::QUALIFIED_TIMEFRAMES, true)
        );

        return $qualified ? self::STAGE_QUALIFIED : self::STAGE_DISQUALIFIED;
    }

    public function redirectUrl(string $stage): ?string
    {
        return match ($stage) {
            self::STAGE_QUALIFIED    => self::URL_QUALIFIED,
            self::STAGE_DISQUALIFIED => self::URL_DISQUALIFIED,
            default                  => null,
        };
    }
}
