<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI analysis validator.
 *
 * Responsible only for validating and normalizing structured
 * AI job-analysis output.
 *
 * Expected contract:
 *
 * [
 *     'summary'            => string,
 *     'category'           => 'web'|'data'|'other',
 *     'complexity'         => 1..5,
 *     'ai_feasible'        => 'yes'|'partial'|'no',
 *     'ai_feasible_reason' => string,
 *     'estimated_hours'    => float >= 0,
 *     'plan'               => string[],
 *     'red_flags'          => string[],
 *     'recommendation'     => 'take'|'skip',
 *     'confidence'         => 0.0..1.0,
 * ]
 *
 * This class does not:
 *
 * - parse provider JSON;
 * - perform HTTP requests;
 * - save analyses;
 * - change job status;
 * - decide pipeline transitions.
 */
class AIJP_AI_Validator
{
    /**
     * Allowed job categories.
     */
    private const ALLOWED_CATEGORIES = [
        'web',
        'data',
        'other',
    ];

    /**
     * Allowed AI feasibility values.
     */
    private const ALLOWED_FEASIBILITY = [
        'yes',
        'partial',
        'no',
    ];

    /**
     * Allowed recommendations.
     */
    private const ALLOWED_RECOMMENDATIONS = [
        'take',
        'skip',
    ];

    /**
     * Maximum summary length.
     *
     * The prompt asks for 2-3 sentences, but the validator keeps
     * a reasonable technical safety limit instead of attempting
     * to count natural-language sentences.
     */
    private const MAX_SUMMARY_LENGTH = 2000;

    /**
     * Maximum feasibility-reason length.
     *
     * Defined directly by the analysis prompt contract.
     */
    private const MAX_FEASIBILITY_REASON_LENGTH = 200;

    /**
     * Maximum estimated work time.
     *
     * This is only a corruption / absurd-value guard.
     */
    private const MAX_ESTIMATED_HOURS = 10000.0;

    /**
     * Maximum number of plan steps.
     */
    private const MAX_PLAN_ITEMS = 20;

    /**
     * Maximum number of red flags.
     */
    private const MAX_RED_FLAGS = 20;

    /**
     * Maximum length of one plan step.
     */
    private const MAX_PLAN_ITEM_LENGTH = 1000;

    /**
     * Maximum length of one red flag.
     */
    private const MAX_RED_FLAG_LENGTH = 1000;

    /**
     * Validate an AI analysis without returning normalized data.
     *
     * Compatibility method for callers interested only in validity.
     *
     * @param array<string,mixed> $analysis AI analysis.
     *
     * @return bool|WP_Error
     */
    public function validate_analysis(
        array $analysis
    ): bool|WP_Error {
        $result =
            $this->normalize_analysis(
                $analysis
            );

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Compatibility alias.
     *
     * @param array<string,mixed> $analysis AI analysis.
     *
     * @return bool|WP_Error
     */
    public function validate(
        array $analysis
    ): bool|WP_Error {
        return $this->validate_analysis(
            $analysis
        );
    }

    /**
     * Validate and normalize an AI analysis.
     *
     * This method is the authoritative schema boundary between
     * untrusted model output and the rest of the application.
     *
     * @param array<string,mixed> $analysis Raw analysis.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function normalize_analysis(
        array $analysis
    ): array|WP_Error {
        if ($analysis === []) {
            return new WP_Error(
                'aijp_ai_empty_analysis',
                __(
                    'AI analysis is empty.',
                    'ai-job-pipeline'
                )
            );
        }

        /*
         * Discard unsupported model-created fields.
         *
         * Model output must never be able to introduce arbitrary
         * properties into storage or downstream application logic.
         */
        $analysis =
            $this->sanitize_top_level(
                $analysis
            );

        $required_fields = [
            'summary',
            'category',
            'complexity',
            'ai_feasible',
            'ai_feasible_reason',
            'estimated_hours',
            'plan',
            'red_flags',
            'recommendation',
            'confidence',
        ];

        foreach ($required_fields as $field) {
            if (
                !array_key_exists(
                    $field,
                    $analysis
                )
            ) {
                return new WP_Error(
                    'aijp_ai_missing_field',
                    sprintf(
                        __(
                            'AI analysis is missing required field: %s',
                            'ai-job-pipeline'
                        ),
                        $field
                    ),
                    [
                        'field' =>
                            $field,
                    ]
                );
            }
        }

        /*
         * summary
         */
        $summary =
            $this->validate_required_string(
                $analysis['summary'],
                'summary',
                self::MAX_SUMMARY_LENGTH
            );

        if (is_wp_error($summary)) {
            return $summary;
        }

        /*
         * category
         */
        $category =
            $this->validate_enum(
                $analysis['category'],
                'category',
                self::ALLOWED_CATEGORIES
            );

        if (is_wp_error($category)) {
            return $category;
        }

        /*
         * complexity
         */
        $complexity =
            $this->validate_integer_range(
                $analysis['complexity'],
                'complexity',
                1,
                5
            );

        if (is_wp_error($complexity)) {
            return $complexity;
        }

        /*
         * ai_feasible
         */
        $ai_feasible =
            $this->validate_enum(
                $analysis['ai_feasible'],
                'ai_feasible',
                self::ALLOWED_FEASIBILITY
            );

        if (is_wp_error($ai_feasible)) {
            return $ai_feasible;
        }

        /*
         * ai_feasible_reason
         */
        $ai_feasible_reason =
            $this->validate_required_string(
                $analysis['ai_feasible_reason'],
                'ai_feasible_reason',
                self::MAX_FEASIBILITY_REASON_LENGTH
            );

        if (is_wp_error($ai_feasible_reason)) {
            return $ai_feasible_reason;
        }

        /*
         * estimated_hours
         */
        $estimated_hours =
            $this->validate_estimated_hours(
                $analysis['estimated_hours']
            );

        if (is_wp_error($estimated_hours)) {
            return $estimated_hours;
        }

        /*
         * plan
         *
         * The field itself is mandatory, but an empty array is valid.
         *
         * Example:
         * an obviously unsuitable job may legitimately have no
         * execution plan.
         */
        $plan =
            $this->validate_string_list(
                $analysis['plan'],
                'plan',
                self::MAX_PLAN_ITEMS,
                self::MAX_PLAN_ITEM_LENGTH
            );

        if (is_wp_error($plan)) {
            return $plan;
        }

        /*
         * red_flags
         *
         * Empty array is valid.
         */
        $red_flags =
            $this->validate_string_list(
                $analysis['red_flags'],
                'red_flags',
                self::MAX_RED_FLAGS,
                self::MAX_RED_FLAG_LENGTH
            );

        if (is_wp_error($red_flags)) {
            return $red_flags;
        }

        /*
         * recommendation
         */
        $recommendation =
            $this->validate_enum(
                $analysis['recommendation'],
                'recommendation',
                self::ALLOWED_RECOMMENDATIONS
            );

        if (is_wp_error($recommendation)) {
            return $recommendation;
        }

        /*
         * confidence
         */
        $confidence =
            $this->validate_float_range(
                $analysis['confidence'],
                'confidence',
                0.0,
                1.0
            );

        if (is_wp_error($confidence)) {
            return $confidence;
        }

        /*
         * Minimal semantic consistency.
         *
         * "ai_feasible = no" and "recommendation = take" contradict
         * each other directly.
         *
         * We deliberately do NOT invent confidence thresholds here.
         * Low confidence is valid model output and must remain visible
         * to the pipeline.
         */
        if (
            $ai_feasible === 'no' &&
            $recommendation === 'take'
        ) {
            return new WP_Error(
                'aijp_ai_inconsistent_recommendation',
                __(
                    'AI analysis is inconsistent: recommendation cannot be "take" when AI feasibility is "no".',
                    'ai-job-pipeline'
                ),
                [
                    'ai_feasible' =>
                        $ai_feasible,

                    'recommendation' =>
                        $recommendation,
                ]
            );
        }

        return [
            'summary' =>
                $summary,

            'category' =>
                $category,

            'complexity' =>
                $complexity,

            'ai_feasible' =>
                $ai_feasible,

            'ai_feasible_reason' =>
                $ai_feasible_reason,

            'estimated_hours' =>
                $estimated_hours,

            'plan' =>
                $plan,

            'red_flags' =>
                $red_flags,

            'recommendation' =>
                $recommendation,

            'confidence' =>
                $confidence,
        ];
    }

    /**
     * Remove unsupported top-level fields.
     *
     * @param array<string,mixed> $analysis Analysis.
     *
     * @return array<string,mixed>
     */
    private function sanitize_top_level(
        array $analysis
    ): array {
        $allowed_fields = [
            'summary',
            'category',
            'complexity',
            'ai_feasible',
            'ai_feasible_reason',
            'estimated_hours',
            'plan',
            'red_flags',
            'recommendation',
            'confidence',
        ];

        $clean = [];

        foreach ($allowed_fields as $field) {
            if (
                array_key_exists(
                    $field,
                    $analysis
                )
            ) {
                $clean[$field] =
                    $analysis[$field];
            }
        }

        return $clean;
    }

    /**
     * Validate a required string.
     *
     * @param mixed  $value      Value.
     * @param string $field      Field name.
     * @param int    $max_length Maximum length.
     *
     * @return string|WP_Error
     */
    private function validate_required_string(
        mixed $value,
        string $field,
        int $max_length
    ): string|WP_Error {
        if (!is_string($value)) {
            return new WP_Error(
                'aijp_ai_invalid_string',
                sprintf(
                    __(
                        'AI field "%s" must be a string.',
                        'ai-job-pipeline'
                    ),
                    $field
                ),
                [
                    'field' =>
                        $field,
                ]
            );
        }

        $value =
            $this->normalize_string(
                $value
            );

        if ($value === '') {
            return new WP_Error(
                'aijp_ai_empty_string',
                sprintf(
                    __(
                        'AI field "%s" cannot be empty.',
                        'ai-job-pipeline'
                    ),
                    $field
                ),
                [
                    'field' =>
                        $field,
                ]
            );
        }

        if (
            $this->string_length(
                $value
            ) > $max_length
        ) {
            return new WP_Error(
                'aijp_ai_string_too_long',
                sprintf(
                    __(
                        'AI field "%1$s" exceeds the maximum length of %2$d characters.',
                        'ai-job-pipeline'
                    ),
                    $field,
                    $max_length
                ),
                [
                    'field' =>
                        $field,

                    'max_length' =>
                        $max_length,
                ]
            );
        }

        return $value;
    }

    /**
     * Validate an enum field.
     *
     * Values are trimmed and converted to lowercase before validation.
     *
     * @param mixed    $value   Value.
     * @param string   $field   Field name.
     * @param string[] $allowed Allowed values.
     *
     * @return string|WP_Error
     */
    private function validate_enum(
        mixed $value,
        string $field,
        array $allowed
    ): string|WP_Error {
        if (!is_string($value)) {
            return new WP_Error(
                'aijp_ai_invalid_enum',
                sprintf(
                    __(
                        'AI field "%s" must be a string.',
                        'ai-job-pipeline'
                    ),
                    $field
                ),
                [
                    'field' =>
                        $field,
                ]
            );
        }

        $value =
            strtolower(
                trim($value)
            );

        if (
            !in_array(
                $value,
                $allowed,
                true
            )
        ) {
            return new WP_Error(
                'aijp_ai_invalid_enum_value',
                sprintf(
                    __(
                        'AI field "%1$s" contains unsupported value "%2$s".',
                        'ai-job-pipeline'
                    ),
                    $field,
                    $value
                ),
                [
                    'field' =>
                        $field,

                    'value' =>
                        $value,

                    'allowed' =>
                        $allowed,
                ]
            );
        }

        return $value;
    }

    /**
     * Validate an integer inside a range.
     *
     * Numeric strings containing an integer are accepted because
     * model-generated JSON may occasionally encode a number as text.
     *
     * Floats such as 3.5 are not accepted for integer fields.
     *
     * @param mixed  $value Value.
     * @param string $field Field name.
     * @param int    $min   Minimum.
     * @param int    $max   Maximum.
     *
     * @return int|WP_Error
     */
    private function validate_integer_range(
        mixed $value,
        string $field,
        int $min,
        int $max
    ): int|WP_Error {
        if (is_int($value)) {
            $integer =
                $value;
        } elseif (
            is_string($value) &&
            preg_match(
                '/^-?\d+$/',
                trim($value)
            )
        ) {
            $integer =
                (int) trim($value);
        } else {
            return new WP_Error(
                'aijp_ai_invalid_integer',
                sprintf(
                    __(
                        'AI field "%s" must be an integer.',
                        'ai-job-pipeline'
                    ),
                    $field
                ),
                [
                    'field' =>
                        $field,
                ]
            );
        }

        if (
            $integer < $min ||
            $integer > $max
        ) {
            return new WP_Error(
                'aijp_ai_integer_out_of_range',
                sprintf(
                    __(
                        'AI field "%1$s" must be between %2$d and %3$d.',
                        'ai-job-pipeline'
                    ),
                    $field,
                    $min,
                    $max
                ),
                [
                    'field' =>
                        $field,

                    'min' =>
                        $min,

                    'max' =>
                        $max,

                    'value' =>
                        $integer,
                ]
            );
        }

        return $integer;
    }

    /**
     * Validate estimated work hours.
     *
     * Fractional values are supported.
     *
     * Zero is valid. For example, an impossible / irrelevant job
     * may legitimately have:
     *
     * ai_feasible = no
     * estimated_hours = 0
     *
     * @param mixed $value Estimated hours.
     *
     * @return float|WP_Error
     */
    private function validate_estimated_hours(
        mixed $value
    ): float|WP_Error {
        $number =
            $this->numeric_value(
                $value,
                'estimated_hours'
            );

        if (is_wp_error($number)) {
            return $number;
        }

        if ($number < 0.0) {
            return new WP_Error(
                'aijp_ai_invalid_estimated_hours_range',
                __(
                    'AI field "estimated_hours" cannot be negative.',
                    'ai-job-pipeline'
                ),
                [
                    'value' =>
                        $number,
                ]
            );
        }

        if (
            $number >
            self::MAX_ESTIMATED_HOURS
        ) {
            return new WP_Error(
                'aijp_ai_estimated_hours_too_large',
                sprintf(
                    __(
                        'AI field "estimated_hours" exceeds the maximum allowed value of %s.',
                        'ai-job-pipeline'
                    ),
                    (string) self::MAX_ESTIMATED_HOURS
                ),
                [
                    'value' =>
                        $number,

                    'max' =>
                        self::MAX_ESTIMATED_HOURS,
                ]
            );
        }

        return round(
            $number,
            2
        );
    }

    /**
     * Validate an array containing strings.
     *
     * Empty arrays are valid.
     *
     * Empty string entries are removed.
     * Duplicate entries are removed while preserving order.
     *
     * @param mixed  $value           Value.
     * @param string $field           Field name.
     * @param int    $max_items       Maximum items.
     * @param int    $max_item_length Maximum length of one item.
     *
     * @return string[]|WP_Error
     */
    private function validate_string_list(
        mixed $value,
        string $field,
        int $max_items,
        int $max_item_length
    ): array|WP_Error {
        if (!is_array($value)) {
            return new WP_Error(
                'aijp_ai_invalid_list',
                sprintf(
                    __(
                        'AI field "%s" must be an array.',
                        'ai-job-pipeline'
                    ),
                    $field
                ),
                [
                    'field' =>
                        $field,
                ]
            );
        }

        /*
         * Reject extremely large raw arrays before processing them.
         */
        if (
            count($value) >
            $max_items
        ) {
            return new WP_Error(
                'aijp_ai_list_too_large',
                sprintf(
                    __(
                        'AI field "%1$s" cannot contain more than %2$d items.',
                        'ai-job-pipeline'
                    ),
                    $field,
                    $max_items
                ),
                [
                    'field' =>
                        $field,

                    'max_items' =>
                        $max_items,
                ]
            );
        }

        $result = [];

        foreach ($value as $index => $item) {
            if (!is_string($item)) {
                return new WP_Error(
                    'aijp_ai_invalid_list_item',
                    sprintf(
                        __(
                            'AI field "%1$s" contains a non-string item at index %2$d.',
                            'ai-job-pipeline'
                        ),
                        $field,
                        (int) $index
                    ),
                    [
                        'field' =>
                            $field,

                        'index' =>
                            $index,
                    ]
                );
            }

            $item =
                $this->normalize_string(
                    $item
                );

            /*
             * Ignore empty list entries rather than allowing meaningless
             * strings to propagate into storage.
             */
            if ($item === '') {
                continue;
            }

            if (
                $this->string_length(
                    $item
                ) > $max_item_length
            ) {
                return new WP_Error(
                    'aijp_ai_list_item_too_long',
                    sprintf(
                        __(
                            'An item in AI field "%1$s" exceeds the maximum length of %2$d characters.',
                            'ai-job-pipeline'
                        ),
                        $field,
                        $max_item_length
                    ),
                    [
                        'field' =>
                            $field,

                        'index' =>
                            $index,

                        'max_length' =>
                            $max_item_length,
                    ]
                );
            }

            if (
                !in_array(
                    $item,
                    $result,
                    true
                )
            ) {
                $result[] =
                    $item;
            }
        }

        return $result;
    }

    /**
     * Validate a floating-point value inside a range.
     *
     * @param mixed  $value Value.
     * @param string $field Field name.
     * @param float  $min   Minimum.
     * @param float  $max   Maximum.
     *
     * @return float|WP_Error
     */
    private function validate_float_range(
        mixed $value,
        string $field,
        float $min,
        float $max
    ): float|WP_Error {
        $number =
            $this->numeric_value(
                $value,
                $field
            );

        if (is_wp_error($number)) {
            return $number;
        }

        if (
            $number < $min ||
            $number > $max
        ) {
            return new WP_Error(
                'aijp_ai_float_out_of_range',
                sprintf(
                    __(
                        'AI field "%1$s" must be between %2$s and %3$s.',
                        'ai-job-pipeline'
                    ),
                    $field,
                    (string) $min,
                    (string) $max
                ),
                [
                    'field' =>
                        $field,

                    'value' =>
                        $number,

                    'min' =>
                        $min,

                    'max' =>
                        $max,
                ]
            );
        }

        return round(
            $number,
            4
        );
    }

    /**
     * Normalize a numeric model value.
     *
     * Accepts:
     *
     * - int;
     * - float;
     * - numeric string.
     *
     * Rejects:
     *
     * - booleans;
     * - arrays;
     * - objects;
     * - NaN;
     * - infinity.
     *
     * @param mixed  $value Value.
     * @param string $field Field name.
     *
     * @return float|WP_Error
     */
    private function numeric_value(
        mixed $value,
        string $field
    ): float|WP_Error {
        if (
            is_bool($value) ||
            (
                !is_int($value) &&
                !is_float($value) &&
                !(
                    is_string($value) &&
                    is_numeric(
                        trim($value)
                    )
                )
            )
        ) {
            return new WP_Error(
                'aijp_ai_invalid_numeric_value',
                sprintf(
                    __(
                        'AI field "%s" must be numeric.',
                        'ai-job-pipeline'
                    ),
                    $field
                ),
                [
                    'field' =>
                        $field,
                ]
            );
        }

        $number =
            (float) $value;

        if (
            !is_finite(
                $number
            )
        ) {
            return new WP_Error(
                'aijp_ai_non_finite_numeric_value',
                sprintf(
                    __(
                        'AI field "%s" must contain a finite number.',
                        'ai-job-pipeline'
                    ),
                    $field
                ),
                [
                    'field' =>
                        $field,
                ]
            );
        }

        return $number;
    }

    /**
     * Normalize a model-generated string.
     *
     * We preserve line breaks because summaries, plan steps and
     * explanations may legitimately contain them, while removing
     * invalid UTF-8 and normalizing line endings.
     *
     * @param string $value Value.
     *
     * @return string
     */
    private function normalize_string(
        string $value
    ): string {
        if (
            function_exists(
                'wp_check_invalid_utf8'
            )
        ) {
            $value =
                wp_check_invalid_utf8(
                    $value,
                    true
                );
        }

        $value =
            str_replace(
                [
                    "\r\n",
                    "\r",
                ],
                "\n",
                $value
            );

        /*
         * Remove NUL bytes and other problematic zero-width control
         * characters that have no useful role in analysis data.
         */
        $value =
            str_replace(
                "\0",
                '',
                $value
            );

        return trim(
            $value
        );
    }

    /**
     * Get UTF-8 aware string length where possible.
     *
     * @param string $value Value.
     *
     * @return int
     */
    private function string_length(
        string $value
    ): int {
        if (
            function_exists(
                'mb_strlen'
            )
        ) {
            return (int) mb_strlen(
                $value,
                'UTF-8'
            );
        }

        return strlen(
            $value
        );
    }
}