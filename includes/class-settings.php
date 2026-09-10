<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin settings manager.
 *
 * Responsibilities:
 *
 * - register plugin settings;
 * - render admin settings fields;
 * - sanitize configuration values;
 * - provide a single access point to settings;
 * - expose AI budget configuration.
 *
 * This class does NOT:
 *
 * - schedule cron jobs;
 * - perform AI requests;
 * - write AI usage records;
 * - enforce runtime AI budgets.
 */
class AIJP_Settings
{
    /**
     * WordPress option name.
     */
    public const OPTION_NAME = 'aijp_settings';

    /**
     * Default AI provider.
     */
    private const DEFAULT_AI_PROVIDER = 'openai';

    /**
     * Default AI model.
     */
    private const DEFAULT_AI_MODEL = 'gpt-5';

    /**
     * Default AI request timeout.
     */
    private const DEFAULT_AI_TIMEOUT = 60;

    /**
     * Default AI temperature.
     */
    private const DEFAULT_AI_TEMPERATURE = 0.2;

    /**
     * Default daily AI budget in USD.
     */
    private const DEFAULT_AI_DAILY_BUDGET_USD = 20.00;

    /**
     * Default monthly AI budget in USD.
     */
    private const DEFAULT_AI_MONTHLY_BUDGET_USD = 20.00;

    /**
     * Default import interval in minutes.
     */
    private const DEFAULT_CRON_INTERVAL = 5;

    /**
     * Register plugin settings.
     *
     * @return void
     */
    public function register(): void
    {
        register_setting(
            'aijp_settings_group',
            self::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [
                    $this,
                    'sanitize',
                ],
                'default'           => $this->get_defaults(),
            ]
        );

        add_settings_section(
            'aijp_general',
            __(
                'General Settings',
                'ai-job-pipeline'
            ),
            '__return_false',
            'aijp-settings'
        );

        /*
         * ============================================================
         * AI SETTINGS
         * ============================================================
         */

        $this->add_field(
            'ai_provider',
            __(
                'AI Provider',
                'ai-job-pipeline'
            ),
            'select'
        );

        $this->add_field(
            'ai_api_key',
            __(
                'OpenAI API Key',
                'ai-job-pipeline'
            ),
            'password'
        );

        $this->add_field(
            'ai_model',
            __(
                'AI Model',
                'ai-job-pipeline'
            ),
            'text'
        );

        $this->add_field(
            'ai_timeout',
            __(
                'AI Request Timeout',
                'ai-job-pipeline'
            ),
            'number'
        );

        $this->add_field(
            'ai_temperature',
            __(
                'AI Temperature',
                'ai-job-pipeline'
            ),
            'number'
        );

        /*
         * ============================================================
         * AI BUDGET
         * ============================================================
         */

        $this->add_field(
            'ai_budget_enabled',
            __(
                'Enable AI Budget Limit',
                'ai-job-pipeline'
            ),
            'checkbox'
        );

        $this->add_field(
            'ai_daily_budget_usd',
            __(
                'Daily AI Budget (USD)',
                'ai-job-pipeline'
            ),
            'number'
        );

        $this->add_field(
            'ai_monthly_budget_usd',
            __(
                'Monthly AI Budget (USD)',
                'ai-job-pipeline'
            ),
            'number'
        );

        /*
         * ============================================================
         * TELEGRAM
         * ============================================================
         */

        $this->add_field(
            'telegram_token',
            __(
                'Telegram Bot Token',
                'ai-job-pipeline'
            ),
            'password'
        );

        /*
         * ============================================================
         * CRON
         * ============================================================
         */

        $this->add_field(
            'cron_interval',
            __(
                'Import interval (minutes)',
                'ai-job-pipeline'
            ),
            'number'
        );

        /*
         * ============================================================
         * DEBUG
         * ============================================================
         */

        $this->add_field(
            'debug',
            __(
                'Debug Mode',
                'ai-job-pipeline'
            ),
            'checkbox'
        );
    }

    /**
     * Get a plugin setting.
     *
     * @param string $key     Setting key.
     * @param mixed  $default Default value.
     *
     * @return mixed
     */
    public static function get(
        string $key,
        mixed $default = null
    ): mixed {
        $options = get_option(
            self::OPTION_NAME,
            []
        );

        if (!is_array($options)) {
            $options = [];
        }

        /*
         * Backward compatibility with the old
         * openai_api_key setting.
         */
        if (
            $key === 'ai_api_key'
            && empty($options['ai_api_key'])
            && !empty($options['openai_api_key'])
        ) {
            return $options['openai_api_key'];
        }

        /*
         * Use the class default when the caller did
         * not explicitly provide a fallback.
         */
        if (
            !array_key_exists($key, $options)
            && $default === null
        ) {
            $defaults = self::default_values();

            if (array_key_exists($key, $defaults)) {
                return $defaults[$key];
            }
        }

        if (!array_key_exists($key, $options)) {
            return $default;
        }

        return $options[$key];
    }

    /**
     * Get all normalized settings.
     *
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        $options = get_option(
            self::OPTION_NAME,
            []
        );

        if (!is_array($options)) {
            $options = [];
        }

        return array_merge(
            self::default_values(),
            $options
        );
    }

    /**
     * Get default settings.
     *
     * @return array<string,mixed>
     */
    private function get_defaults(): array
    {
        return self::default_values();
    }

    /**
     * Static default settings.
     *
     * @return array<string,mixed>
     */
    private static function default_values(): array
    {
        return [
            'ai_provider' =>
                self::DEFAULT_AI_PROVIDER,

            'ai_api_key' =>
                '',

            'ai_model' =>
                self::DEFAULT_AI_MODEL,

            'ai_timeout' =>
                self::DEFAULT_AI_TIMEOUT,

            'ai_temperature' =>
                self::DEFAULT_AI_TEMPERATURE,

            'ai_budget_enabled' =>
                1,

            'ai_daily_budget_usd' =>
                self::DEFAULT_AI_DAILY_BUDGET_USD,

            'ai_monthly_budget_usd' =>
                self::DEFAULT_AI_MONTHLY_BUDGET_USD,

            'telegram_token' =>
                '',

            'cron_interval' =>
                self::DEFAULT_CRON_INTERVAL,

            'debug' =>
                0,
        ];
    }

    /**
     * Add a settings field.
     *
     * @param string $id    Field ID.
     * @param string $title Field title.
     * @param string $type  Field type.
     *
     * @return void
     */
    private function add_field(
        string $id,
        string $title,
        string $type = 'text'
    ): void {
        add_settings_field(
            $id,
            $title,
            [
                $this,
                'render_field',
            ],
            'aijp-settings',
            'aijp_general',
            [
                'id'   => $id,
                'type' => $type,
            ]
        );
    }

    /**
     * Render a settings field.
     *
     * @param array<string,mixed> $args Field arguments.
     *
     * @return void
     */
    public function render_field(
        array $args
    ): void {
        $id = isset($args['id'])
            ? (string) $args['id']
            : '';

        $type = isset($args['type'])
            ? (string) $args['type']
            : 'text';

        if ($id === '') {
            return;
        }

        $value = self::get(
            $id,
            self::default_values()[$id] ?? ''
        );

        /*
         * ============================================================
         * CHECKBOX
         * ============================================================
         */

        if ($type === 'checkbox') {
            echo '<label>';

            echo '<input type="checkbox" name="' .
                esc_attr(self::OPTION_NAME) .
                '[' .
                esc_attr($id) .
                ']" value="1" ' .
                checked(
                    !empty($value),
                    true,
                    false
                ) .
                '>';

            echo ' ';

            echo esc_html(
                $this->get_checkbox_description($id)
            );

            echo '</label>';

            return;
        }

        /*
         * ============================================================
         * SELECT
         * ============================================================
         */

        if ($type === 'select') {
            $this->render_select_field(
                $id,
                $value
            );

            return;
        }

        /*
         * ============================================================
         * TEXT / NUMBER / PASSWORD
         * ============================================================
         */

        $attributes = $this->get_field_attributes(
            $id
        );

        $description = $this->get_field_description(
            $id
        );

        echo '<input class="regular-text" type="' .
            esc_attr($type) .
            '" name="' .
            esc_attr(self::OPTION_NAME) .
            '[' .
            esc_attr($id) .
            ']" value="' .
            esc_attr(
                (string) $value
            ) .
            '"' .
            $attributes .
            '>';

        if ($description !== '') {
            echo '<p class="description">' .
                esc_html($description) .
                '</p>';
        }
    }

    /**
     * Render select fields.
     *
     * @param string $id    Field ID.
     * @param mixed  $value Current value.
     *
     * @return void
     */
    private function render_select_field(
        string $id,
        mixed $value
    ): void {
        if ($id !== 'ai_provider') {
            return;
        }

        echo '<select name="' .
            esc_attr(self::OPTION_NAME) .
            '[' .
            esc_attr($id) .
            ']">';

        echo '<option value="openai" ' .
            selected(
                (string) $value,
                'openai',
                false
            ) .
            '>';

        echo esc_html__(
            'OpenAI',
            'ai-job-pipeline'
        );

        echo '</option>';

        echo '</select>';
    }

    /**
     * Get checkbox description.
     *
     * @param string $id Field ID.
     *
     * @return string
     */
    private function get_checkbox_description(
        string $id
    ): string {
        return match ($id) {
            'ai_budget_enabled' =>
                __(
                    'Stop new AI analysis requests when the configured daily or monthly limit is reached.',
                    'ai-job-pipeline'
                ),

            'debug' =>
                __(
                    'Enable debug logging.',
                    'ai-job-pipeline'
                ),

            default =>
                '',
        };
    }

    /**
     * Get field description.
     *
     * @param string $id Field ID.
     *
     * @return string
     */
    private function get_field_description(
        string $id
    ): string {
        return match ($id) {
            'ai_daily_budget_usd' =>
                __(
                    'Maximum estimated AI spending allowed per calendar day.',
                    'ai-job-pipeline'
                ),

            'ai_monthly_budget_usd' =>
                __(
                    'Maximum estimated AI spending allowed per calendar month.',
                    'ai-job-pipeline'
                ),

            'ai_api_key' =>
                __(
                    'Leave empty to keep the currently stored key.',
                    'ai-job-pipeline'
                ),

            'ai_temperature' =>
                __(
                    'Controls response randomness. Lower values are more deterministic.',
                    'ai-job-pipeline'
                ),

            default =>
                '',
        };
    }

    /**
     * Get HTML attributes for a field.
     *
     * @param string $id Field ID.
     *
     * @return string
     */
    private function get_field_attributes(
        string $id
    ): string {
        return match ($id) {
            'cron_interval' =>
                ' min="5" max="1440" step="1"',

            'ai_timeout' =>
                ' min="10" max="300" step="1"',

            'ai_temperature' =>
                ' min="0" max="2" step="0.1"',

            'ai_daily_budget_usd' =>
                ' min="0" max="100000" step="0.01"',

            'ai_monthly_budget_usd' =>
                ' min="0" max="1000000" step="0.01"',

            default =>
                '',
        };
    }

    /**
     * Sanitize plugin settings.
     *
     * This method only validates and normalizes settings.
     *
     * IMPORTANT:
     * It intentionally does not initialize or reschedule cron.
     *
     * @param array<string,mixed> $input Raw settings.
     *
     * @return array<string,mixed>
     */
    public function sanitize(
        array $input
    ): array {
        $existing_settings = get_option(
            self::OPTION_NAME,
            []
        );

        if (!is_array($existing_settings)) {
            $existing_settings = [];
        }

        /*
         * ============================================================
         * AI PROVIDER
         * ============================================================
         */

        $provider = isset($input['ai_provider'])
            ? strtolower(
                sanitize_key(
                    (string) $input['ai_provider']
                )
            )
            : self::DEFAULT_AI_PROVIDER;

        /*
         * Only OpenAI is implemented at this stage.
         */
        if ($provider !== 'openai') {
            $provider = self::DEFAULT_AI_PROVIDER;
        }

        /*
         * ============================================================
         * API KEY
         * ============================================================
         */

        $api_key = isset($input['ai_api_key'])
            ? trim(
                (string) $input['ai_api_key']
            )
            : '';

        /*
         * Empty password field means:
         * keep existing key.
         *
         * Also support the old option key.
         */
        if ($api_key === '') {
            if (
                !empty(
                    $existing_settings['ai_api_key']
                )
            ) {
                $api_key =
                    (string)
                    $existing_settings['ai_api_key'];
            } elseif (
                !empty(
                    $existing_settings['openai_api_key']
                )
            ) {
                $api_key =
                    (string)
                    $existing_settings['openai_api_key'];
            }
        }

        /*
         * Do not run the API key through sanitize_text_field().
         *
         * API keys are opaque credentials and should only be trimmed.
         */
        $api_key = trim($api_key);

        /*
         * ============================================================
         * MODEL
         * ============================================================
         */

        $model = isset($input['ai_model'])
            ? sanitize_text_field(
                (string) $input['ai_model']
            )
            : self::DEFAULT_AI_MODEL;

        if ($model === '') {
            $model = self::DEFAULT_AI_MODEL;
        }

        /*
         * ============================================================
         * TIMEOUT
         * ============================================================
         */

        $ai_timeout = absint(
            $input['ai_timeout']
            ?? self::DEFAULT_AI_TIMEOUT
        );

        $ai_timeout = max(
            10,
            min(
                300,
                $ai_timeout
            )
        );

        /*
         * ============================================================
         * TEMPERATURE
         * ============================================================
         */

        $ai_temperature =
            $this->sanitize_temperature(
                $input['ai_temperature']
                ?? self::DEFAULT_AI_TEMPERATURE
            );

        /*
         * ============================================================
         * AI BUDGET
         * ============================================================
         */

        $ai_budget_enabled =
            !empty(
                $input['ai_budget_enabled']
            )
                ? 1
                : 0;

        $daily_budget =
            $this->sanitize_money(
                $input['ai_daily_budget_usd']
                ?? self::DEFAULT_AI_DAILY_BUDGET_USD,
                self::DEFAULT_AI_DAILY_BUDGET_USD
            );

        $monthly_budget =
            $this->sanitize_money(
                $input['ai_monthly_budget_usd']
                ?? self::DEFAULT_AI_MONTHLY_BUDGET_USD,
                self::DEFAULT_AI_MONTHLY_BUDGET_USD
            );

        /*
         * A monthly limit smaller than the daily limit would make
         * the daily setting potentially misleading.
         *
         * Keep both values valid independently, but if both are
         * positive ensure monthly >= daily.
         */
        if (
            $monthly_budget > 0
            && $daily_budget > $monthly_budget
        ) {
            $daily_budget = $monthly_budget;
        }

        /*
         * ============================================================
         * TELEGRAM
         * ============================================================
         */

        $telegram_token =
            isset($input['telegram_token'])
                ? trim(
                    (string)
                    $input['telegram_token']
                )
                : '';

        /*
         * Telegram tokens are opaque credentials too.
         */
        $telegram_token = preg_replace(
            '/\s+/',
            '',
            $telegram_token
        );

        if (!is_string($telegram_token)) {
            $telegram_token = '';
        }

        /*
         * ============================================================
         * CRON
         * ============================================================
         */

        $cron_interval = absint(
            $input['cron_interval']
            ?? self::DEFAULT_CRON_INTERVAL
        );

        $cron_interval = max(
            5,
            min(
                1440,
                $cron_interval
            )
        );

        /*
         * ============================================================
         * DEBUG
         * ============================================================
         */

        $debug = !empty(
            $input['debug']
        )
            ? 1
            : 0;

        /*
         * ============================================================
         * RETURN NORMALIZED SETTINGS
         * ============================================================
         */

        return [
            'ai_provider' =>
                $provider,

            'ai_api_key' =>
                $api_key,

            'ai_model' =>
                $model,

            'ai_timeout' =>
                $ai_timeout,

            'ai_temperature' =>
                $ai_temperature,

            'ai_budget_enabled' =>
                $ai_budget_enabled,

            'ai_daily_budget_usd' =>
                $daily_budget,

            'ai_monthly_budget_usd' =>
                $monthly_budget,

            'telegram_token' =>
                $telegram_token,

            'cron_interval' =>
                $cron_interval,

            'debug' =>
                $debug,
        ];
    }

    /**
     * Normalize AI temperature.
     *
     * @param mixed $temperature Raw temperature.
     *
     * @return float
     */
    private function sanitize_temperature(
        mixed $temperature
    ): float {
        if (!is_numeric($temperature)) {
            return self::DEFAULT_AI_TEMPERATURE;
        }

        $temperature = (float) $temperature;

        return round(
            max(
                0.0,
                min(
                    2.0,
                    $temperature
                )
            ),
            2
        );
    }

    /**
     * Sanitize a monetary setting.
     *
     * @param mixed $value         Raw value.
     * @param float $default       Default value.
     *
     * @return float
     */
    private function sanitize_money(
        mixed $value,
        float $default
    ): float {
        if (!is_numeric($value)) {
            return $default;
        }

        $value = (float) $value;

        if ($value < 0) {
            $value = 0.0;
        }

        /*
         * Prevent obviously invalid configuration values.
         */
        $value = min(
            1000000.0,
            $value
        );

        return round(
            $value,
            2
        );
    }
}