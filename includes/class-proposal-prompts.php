<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Proposal (відгук) prompt definitions.
 *
 * Responsible only for:
 *
 * - defining the proposal prompt version;
 * - building the proposal-drafting prompt from job + analysis data.
 *
 * This class does not:
 *
 * - call the AI provider;
 * - save proposals;
 * - send anything to a client.
 *
 * Sending a proposal is always a manual human action (ТЗ Етап 5).
 */
class AIJP_Proposal_Prompts
{
    /**
     * Current proposal prompt version.
     */
    public const VERSION = '1.0';

    /**
     * Maximum job description length sent to the model.
     */
    private const MAX_DESCRIPTION_LENGTH = 4000;

    /**
     * Build the full proposal-drafting prompt for a job.
     *
     * @param array<string,mixed> $job      Job data (as returned by
     *                                       AIJP_Job_Repository).
     * @param array<string,mixed> $analysis Optional AI analysis data
     *                                       for the same job.
     * @param string              $lang     Target language code
     *                                       (e.g. 'en', 'de', 'uk').
     *
     * @return string
     */
    public static function build(
        array $job,
        array $analysis,
        string $lang
    ): string {
        $parts = [];

        $parts[] = self::system();

        $parts[] = '';
        $parts[] = '--- JOB CONTEXT ---';
        $parts[] = self::job_context($job);

        $analysis_context = self::analysis_context($analysis);

        if ($analysis_context !== '') {
            $parts[] = '';
            $parts[] = '--- AI ANALYSIS CONTEXT ---';
            $parts[] = $analysis_context;
        }

        $parts[] = '';
        $parts[] = '--- LANGUAGE ---';
        $parts[] = sprintf(
            'Напиши відгук мовою: %s.',
            $lang !== '' ? $lang : 'en'
        );

        $parts[] = '';
        $parts[] = '--- RESPONSE REQUIREMENT ---';
        $parts[] = 'Поверни лише готовий текст відгуку. Без markdown, без заголовків, без пояснень.';

        return implode("\n", $parts);
    }

    /**
     * System instructions for drafting a proposal.
     *
     * @return string
     */
    private static function system(): string
    {
        return <<<'PROMPT'
Ти — фрилансер, який відгукується на завдання з біржі.

Напиши чернетку відгуку замовнику зі структурою:

1. Коротке привітання без води.
2. Одне-два речення, що показують розуміння суті завдання.
3. План роботи в 3 пунктах.
4. Орієнтовний строк і ціну (якщо бюджет замовника відомий — врахуй його; якщо ні, дай обережну орієнтовну оцінку).
5. Одне уточнювальне запитання по задачі.

Обмеження:

- не більше 150 слів;
- не обіцяй того, чого немає в плані;
- без найвищих ступенів і без слова "passionate";
- не згадуй використання ШІ;
- тон: спокійний, діловий, людяний;
- не вигадуй деталей, яких немає в наданому контексті задачі.
PROMPT;
    }

    /**
     * Build the job section of the prompt.
     *
     * @param array<string,mixed> $job Job data.
     *
     * @return string
     */
    private static function job_context(array $job): string
    {
        $title = (string) ($job['title'] ?? '');

        $description = self::truncate(
            (string) ($job['description'] ?? '')
        );

        $budget_amount = $job['budget_amount'] ?? null;
        $budget_currency = (string) ($job['budget_currency'] ?? '');

        $budget = trim(
            ($budget_amount !== null && $budget_amount !== ''
                ? (string) $budget_amount
                : '') . ' ' . $budget_currency
        );

        $lines = [
            'Заголовок: ' . $title,
            'Бюджет замовника: ' . ($budget !== '' ? $budget : 'не вказано'),
            'Опис задачі:',
            $description,
        ];

        return implode("\n", $lines);
    }

    /**
     * Build the optional AI-analysis section of the prompt.
     *
     * Reusing the existing job-analysis result keeps the proposal's
     * plan consistent with what was already assessed as feasible,
     * instead of the model re-guessing the approach from scratch.
     *
     * @param array<string,mixed> $analysis Analysis data.
     *
     * @return string
     */
    private static function analysis_context(array $analysis): string
    {
        if (empty($analysis)) {
            return '';
        }

        $summary = (string) ($analysis['summary'] ?? '');

        $plan = $analysis['plan'] ?? [];

        if (!is_array($plan)) {
            $plan = [];
        }

        $estimated_hours = $analysis['estimated_hours'] ?? null;

        $lines = [];

        if ($summary !== '') {
            $lines[] = 'Підсумок аналізу: ' . $summary;
        }

        if (!empty($plan)) {
            $lines[] = 'Орієнтовний план (від попереднього ШІ-аналізу):';

            foreach ($plan as $step) {
                $lines[] = '- ' . (string) $step;
            }
        }

        if ($estimated_hours !== null && $estimated_hours !== '') {
            $lines[] = 'Орієнтовна трудомісткість: ' .
                $estimated_hours . ' год.';
        }

        return implode("\n", $lines);
    }

    /**
     * Truncate a job description to a safe token budget.
     *
     * @param string $description Raw description.
     *
     * @return string
     */
    private static function truncate(string $description): string
    {
        $description = wp_strip_all_tags($description);

        if (mb_strlen($description) <= self::MAX_DESCRIPTION_LENGTH) {
            return $description;
        }

        return mb_substr(
            $description,
            0,
            self::MAX_DESCRIPTION_LENGTH
        ) . '…';
    }

    /**
     * Get the current prompt version.
     *
     * @return string
     */
    public static function get_version(): string
    {
        return self::VERSION;
    }
}