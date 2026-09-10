<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI prompt definitions.
 *
 * Responsible only for:
 *
 * - defining prompt versions;
 * - building the job-analysis prompt;
 * - defining the structured output schema;
 * - exposing prompt metadata.
 *
 * This class does not:
 *
 * - call the AI provider;
 * - validate model output;
 * - save analysis results;
 * - calculate AI costs;
 * - load jobs from the database.
 */
class AIJP_AI_Prompts
{
    /**
     * Current job-analysis prompt version.
     */
    public const ANALYSIS_VERSION = '1.3';

    /**
     * Maximum job description length sent to the model.
     *
     * This is deliberately limited to control token usage.
     */
    private const MAX_DESCRIPTION_LENGTH = 6000;

    /**
     * Get system instructions for job analysis.
     *
     * @return string
     */
    public static function analysis_system(): string
    {
        return <<<'PROMPT'
Ти — технічний аналітик фриланс-завдань.

Твоє завдання — оцінити конкретне фриланс-оголошення та визначити:

1. Що саме потрібно зробити.
2. До якої технічної категорії належить задача.
3. Наскільки вона складна для junior-розробника.
4. Чи може junior-розробник реально виконати її за активної допомоги сучасних AI-інструментів.
5. Скільки приблизно реальних робочих годин може зайняти виконання.
6. Які є ризики, невизначеності або проблеми у вимогах.
7. Чи доцільно брати задачу в роботу.

ОСНОВНЕ ПРАВИЛО:

Аналізуй виключно інформацію, яка передана в контексті цього оголошення.

Не вигадуй і не припускай як факт:

- вимоги;
- технології;
- API;
- інтеграції;
- доступи;
- строки;
- бюджет;
- обсяг робіт;
- досвід замовника;
- інфраструктуру;
- приховані умови;
- функціональність, якої немає в описі.

Якщо для оцінки бракує інформації, це має бути відображено через:

- ai_feasible_reason;
- red_flags;
- confidence.

НЕ плутай:

"задачу можна виконати за допомогою AI"

з

"AI самостійно виконає задачу".

ai_feasible означає оцінку того, чи може junior-розробник реально виконати задачу, використовуючи AI як інструмент допомоги.

ОЦІНКА СКЛАДНОСТІ:

complexity = 1:
дуже проста, добре визначена задача без істотних технічних ризиків.

complexity = 2:
проста задача з невеликою кількістю технічних деталей.

complexity = 3:
задача середньої складності або з помітною кількістю невизначеностей.

complexity = 4:
складна задача, що потребує значної технічної роботи, інтеграцій або досвіду.

complexity = 5:
дуже складна задача, високі технічні ризики або вимоги, які суттєво перевищують типовий рівень junior-розробника.

ОЦІНКА AI FEASIBILITY:

yes:
junior-розробник із допомогою AI реалістично може виконати задачу.

partial:
частина задачі реалістично виконується junior-розробником із AI, але є суттєві складні, ризикові або недостатньо визначені частини.

no:
задача нереалістична для junior-розробника навіть за активної допомоги AI.

ОЦІНКА ЧАСУ:

estimated_hours — приблизна кількість реальних робочих годин junior-розробника, а не час генерації відповіді AI.

Не занижуй оцінку лише тому, що AI може швидко написати частину коду.

Враховуй:

- реалізацію;
- інтеграцію;
- налагодження;
- тестування;
- виправлення помилок;
- перевірку результату.

Якщо задача очевидно непридатна для виконання, estimated_hours може бути 0.

RECOMMENDATION:

take:
задачу доцільно брати з урахуванням наявної інформації.

skip:
задачу краще не брати через технічну складність, низьку feasibility, надмірну невизначеність або інші явні ризики.

CONFIDENCE:

confidence — від 0.0 до 1.0.

Якщо інформації недостатньо для надійної оцінки, confidence має бути нижчим за 0.5.

Не підвищуй confidence лише для того, щоб відповідь виглядала впевненою.

ВИМОГИ ДО ВІДПОВІДІ:

- Пиши summary українською мовою.
- ai_feasible_reason — максимум 200 символів.
- Не додавай жодних полів поза переданою JSON-схемою.
- Не змінюй назви полів.
- Не додавай markdown.
- Не додавай пояснення до JSON.
- Поверни тільки один JSON-об'єкт.
PROMPT;
    }

    /**
     * Get structured JSON schema for job analysis.
     *
     * @return array<string,mixed>
     */
    public static function analysis_schema(): array
    {
        return [
            'name' => 'job_analysis',

            'strict' => true,

            'schema' => [
                'type' => 'object',

                'additionalProperties' => false,

                'required' => [
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
                ],

                'properties' => [
                    'summary' => [
                        'type' => 'string',
                        'description' =>
                            'Короткий опис задачі українською мовою у 2-3 реченнях.',
                    ],

                    'category' => [
                        'type' => 'string',
                        'enum' => [
                            'web',
                            'data',
                            'other',
                        ],
                        'description' =>
                            'Технічна категорія задачі.',
                    ],

                    'complexity' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 5,
                        'description' =>
                            'Рівень складності від 1 до 5.',
                    ],

                    'ai_feasible' => [
                        'type' => 'string',
                        'enum' => [
                            'yes',
                            'partial',
                            'no',
                        ],
                        'description' =>
                            'Чи може junior-розробник виконати задачу за активної допомоги AI.',
                    ],

                    'ai_feasible_reason' => [
                        'type' => 'string',
                        'maxLength' => 200,
                        'description' =>
                            'Коротке пояснення feasibility та основних обмежень.',
                    ],

                    'estimated_hours' => [
                        'type' => 'number',
                        'minimum' => 0,
                        'description' =>
                            'Приблизний реальний час виконання у робочих годинах.',
                    ],

                    'plan' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                        ],
                        'description' =>
                            'Практичний покроковий план виконання.',
                    ],

                    'red_flags' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                        ],
                        'description' =>
                            'Ризики, невизначеності або проблеми у вимогах.',
                    ],

                    'recommendation' => [
                        'type' => 'string',
                        'enum' => [
                            'take',
                            'skip',
                        ],
                        'description' =>
                            'Фінальна рекомендація щодо задачі.',
                    ],

                    'confidence' => [
                        'type' => 'number',
                        'minimum' => 0,
                        'maximum' => 1,
                        'description' =>
                            'Впевненість у точності аналізу.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Build complete job-analysis prompt from Agent context.
     *
     * @param array<string,mixed> $context Agent context.
     *
     * @return string
     */
    public static function job_analysis_context(
        array $context
    ): string {
        $job =
            isset($context['job']) &&
            is_array($context['job'])
                ? $context['job']
                : [];

        $parts = [];

        $parts[] =
            self::analysis_system();

        $parts[] = '';

        $parts[] =
            '--- JOB CONTEXT ---';

        $parts[] =
            self::analysis_user(
                $job
            );

        $context_meta =
            self::context_metadata(
                $context
            );

        if ($context_meta !== '') {
            $parts[] = '';

            $parts[] =
                '--- ANALYSIS CONTEXT ---';

            $parts[] =
                $context_meta;
        }

        $parts[] = '';

        $parts[] =
            '--- RESPONSE REQUIREMENT ---';

        $parts[] =
            'Поверни тільки один JSON-об’єкт відповідно до JSON-схеми job_analysis.';

        $parts[] =
            'Не додавай markdown, пояснення або будь-який текст поза JSON.';

        return implode(
            "\n",
            $parts
        );
    }

    /**
     * Build user-facing job data for analysis.
     *
     * @param array<string,mixed> $job Job data.
     *
     * @return string
     */
    public static function analysis_user(
        array $job
    ): string {
        $title =
            self::value(
                $job,
                'title'
            );

        $description =
            self::value(
                $job,
                'description'
            );

        $country =
            self::value(
                $job,
                'country'
            );

        $language =
            self::first_value(
                $job,
                [
                    'lang',
                    'language',
                ]
            );

        $budget_amount =
            self::first_value(
                $job,
                [
                    'budget_amount',
                    'budget',
                    'budget_min',
                ]
            );

        $budget_currency =
            self::first_value(
                $job,
                [
                    'budget_currency',
                    'currency',
                ]
            );

        $source_name =
            self::first_value(
                $job,
                [
                    'source_name',
                    'source',
                ]
            );

        $description =
            self::truncate_description(
                $description
            );

        $parts = [];

        $parts[] =
            'Проаналізуй це фриланс-оголошення.';

        $parts[] = '';

        $parts[] =
            'НАЗВА:';

        $parts[] =
            $title !== ''
                ? $title
                : '[не вказано]';

        $parts[] = '';

        $parts[] =
            'ОПИС:';

        $parts[] =
            $description !== ''
                ? $description
                : '[не вказано]';

        $parts[] = '';

        $parts[] =
            'КРАЇНА:';

        $parts[] =
            $country !== ''
                ? $country
                : '[не вказано]';

        $parts[] = '';

        $parts[] =
            'МОВА ОГОЛОШЕННЯ:';

        $parts[] =
            $language !== ''
                ? $language
                : '[не вказано]';

        $parts[] = '';

        $parts[] =
            'БЮДЖЕТ:';

        if ($budget_amount !== '') {
            $budget_text =
                $budget_amount;

            if ($budget_currency !== '') {
                $budget_text .=
                    ' ' . $budget_currency;
            }

            $parts[] =
                $budget_text;
        } else {
            $parts[] =
                '[не вказано]';
        }

        $parts[] = '';

        $parts[] =
            'ДЖЕРЕЛО:';

        $parts[] =
            $source_name !== ''
                ? $source_name
                : '[не вказано]';

        return implode(
            "\n",
            $parts
        );
    }

    /**
     * Get controlled context metadata.
     *
     * @param array<string,mixed> $context Agent context.
     *
     * @return string
     */
    private static function context_metadata(
        array $context
    ): string {
        $metadata =
            isset($context['metadata']) &&
            is_array($context['metadata'])
                ? $context['metadata']
                : [];

        $agent =
            isset($context['agent']) &&
            is_array($context['agent'])
                ? $context['agent']
                : [];

        $parts = [];

        $context_version =
            self::array_value(
                $metadata,
                'context_version'
            );

        if ($context_version !== '') {
            $parts[] =
                'Версія контексту: ' .
                $context_version;
        }

        $operation =
            self::array_value(
                $agent,
                'operation'
            );

        if ($operation !== '') {
            $parts[] =
                'Операція агента: ' .
                $operation;
        }

        return implode(
            "\n",
            $parts
        );
    }

    /**
     * Return prompt metadata.
     *
     * @return array<string,string>
     */
    public static function analysis_meta(): array
    {
        return [
            'version' =>
                self::ANALYSIS_VERSION,

            'type' =>
                'job_analysis',
        ];
    }

    /**
     * Return current analysis prompt version.
     *
     * @return string
     */
    public static function get_version(): string
    {
        return self::ANALYSIS_VERSION;
    }

    /**
     * Get first non-empty value from a list of keys.
     *
     * @param array<string,mixed> $data Data.
     * @param string[]             $keys Candidate keys.
     *
     * @return string
     */
    private static function first_value(
        array $data,
        array $keys
    ): string {
        foreach ($keys as $key) {
            $value =
                self::array_value(
                    $data,
                    $key
                );

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Safely extract a scalar job value.
     *
     * @param array<string,mixed> $job Job data.
     * @param string              $key Job field.
     *
     * @return string
     */
    private static function value(
        array $job,
        string $key
    ): string {
        return self::array_value(
            $job,
            $key
        );
    }

    /**
     * Safely extract a scalar array value.
     *
     * @param array<string,mixed> $data Data.
     * @param string              $key  Key.
     *
     * @return string
     */
    private static function array_value(
        array $data,
        string $key
    ): string {
        if (
            !array_key_exists(
                $key,
                $data
            )
        ) {
            return '';
        }

        $value =
            $data[$key];

        if (
            $value === null ||
            is_array($value) ||
            is_object($value)
        ) {
            return '';
        }

        return trim(
            (string) $value
        );
    }

    /**
     * Truncate description without breaking UTF-8 text.
     *
     * @param string $description Description.
     *
     * @return string
     */
    private static function truncate_description(
        string $description
    ): string {
        $description =
            trim($description);

        if ($description === '') {
            return '';
        }

        if (
            function_exists('mb_strlen') &&
            function_exists('mb_substr')
        ) {
            if (
                mb_strlen(
                    $description,
                    'UTF-8'
                ) <= self::MAX_DESCRIPTION_LENGTH
            ) {
                return $description;
            }

            return rtrim(
                mb_substr(
                    $description,
                    0,
                    self::MAX_DESCRIPTION_LENGTH,
                    'UTF-8'
                )
            ) . '…';
        }

        if (
            strlen($description) <=
            self::MAX_DESCRIPTION_LENGTH
        ) {
            return $description;
        }

        return rtrim(
            substr(
                $description,
                0,
                self::MAX_DESCRIPTION_LENGTH
            )
        ) . '…';
    }
}
