<?php

declare(strict_types=1);

namespace VIXI\CahSplit;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display copy + deterministic mapping for HTML v1 (12-step) funnel.
 *
 * preceding_step_for_total: which completed step feeds the "Entered" denominator
 * for this row. Step 9 (name) merges after optional step 8, so entrants = completed 7 (zip).
 */
final class FormFunnelStepCatalog
{
    /** @var array<int, array{slug: string, title: string, question: string, preceding_step_for_total?: int}> */
    private const STEPS = [
        1 => [
            'slug'     => 'service_type',
            'title'    => 'Service type',
            'question' => 'What type of accident?',
        ],
        2 => [
            'slug'     => 'attorney',
            'title'    => 'Have attorney',
            'question' => 'Have you ever worked with an attorney for this accident?',
        ],
        3 => [
            'slug'     => 'fault',
            'title'    => 'Fault',
            'question' => 'Was the accident your fault?',
        ],
        4 => [
            'slug'     => 'injury',
            'title'    => 'Injury',
            'question' => 'Did you suffer any injury in the accident?',
        ],
        5 => [
            'slug'     => 'timeframe',
            'title'    => 'Accident happen',
            'question' => 'When did this accident happen?',
        ],
        6 => [
            'slug'     => 'state',
            'title'    => 'State',
            'question' => 'Which state did the accident occur in?',
        ],
        7 => [
            'slug'     => 'zipcode',
            'title'    => 'Zip code',
            'question' => 'What\'s your zip code?',
        ],
        8 => [
            'slug'     => 'insured',
            'title'    => 'Insured',
            'question' => 'Were you insured at the time of the accident? (CA/AZ only)',
        ],
        9 => [
            'slug'                     => 'name',
            'title'                   => 'Name',
            'question'               => 'What\'s your name?',
            /** After optional insured (8): anyone who finishes zip may reach name — including skips. */
            'preceding_step_for_total' => 7,
        ],
        10 => [
            'slug'     => 'phone',
            'title'    => 'Phone',
            'question' => 'What is your phone number?',
        ],
        11 => [
            'slug'     => 'email',
            'title'    => 'Email',
            'question' => 'What is your email address?',
        ],
        12 => [
            'slug'     => 'describe',
            'title'    => 'Describe',
            'question' => 'Briefly describe your accident to us.',
        ],
    ];

    /**
     * @return array<int, array{slug: string, title: string, question: string, preceding_step_for_total?: int}>
     */
    public static function steps(): array
    {
        return self::STEPS;
    }

    public static function maxStep(): int
    {
        return 12;
    }

    public static function slugForStep(int $step): ?string
    {
        return self::STEPS[$step]['slug'] ?? null;
    }

    /**
     * For step ≥2, visitor count denominator source (completed step index), or null for step 1 (pageviews).
     */
    public static function precedingCompletionStepForTotal(int $step): ?int
    {
        if ($step <= 1) {
            return null;
        }
        return self::STEPS[$step]['preceding_step_for_total'] ?? ($step - 1);
    }

    /** @return list<string> */
    public static function allowedSlugs(): array
    {
        return \array_values(\array_map(
            static fn(array $row): string => $row['slug'],
            self::STEPS
        ));
    }

    /**
     * @param array<int, positive-int|0> $completionsByStep step_number => DISTINCT visitor count
     * @return array<string, mixed>
     */
    public static function computeRows(
        int $pageviewsCount,
        array $completionsByStep
    ): array {
        $rows = [];
        for ($step = 1; $step <= self::maxStep(); $step++) {
            $meta       = self::STEPS[$step];
            $completed  = (int) ($completionsByStep[$step] ?? 0);
            $prevComp   = self::precedingCompletionStepForTotal($step);
            $total      = ($prevComp === null)
                ? $pageviewsCount
                : (int) ($completionsByStep[$prevComp] ?? 0);

            $abandon = \max(0, $total - $completed);
            $pctCompleted = $total > 0 ? \round(($completed / $total) * 100.0, 2) : 0.0;
            $pctAbandon = $total > 0 ? \round(($abandon / $total) * 100.0, 2) : 0.0;

            $rows[] = [
                'step'            => $step,
                'slug'            => $meta['slug'],
                'title'           => $meta['title'],
                'question'        => $meta['question'],
                'total'           => $total,
                'completed'       => $completed,
                'pct_completed'   => $pctCompleted,
                'abandonments'    => $abandon,
                'pct_abandon'     => $pctAbandon,
            ];
        }

        $trackedCompletions = (int) ($completionsByStep[self::maxStep()] ?? 0);

        return [
            'steps'                       => $rows,
            /** KPI completions from tracked step-12 events only (same source as totals). */
            'completions'                 => $trackedCompletions,
            /** KPI: conversion rate completions / tracked visits. */
            'conversion_rate'             => $pageviewsCount > 0
                ? \round(($trackedCompletions / $pageviewsCount) * 100.0, 2)
                : 0.0,
            'pageviews'                   => $pageviewsCount,
        ];
    }
}
