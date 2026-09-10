<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin cron manager.
 *
 * Handles:
 * - automatic job imports;
 * - automatic AI processing.
 */
class AIJP_Cron
{
    /**
     * Import cron hook.
     */
    public const IMPORT_HOOK =
        'aijp_run_automatic_import';

    /**
     * AI pipeline cron hook.
     */
    public const AI_PIPELINE_HOOK =
        'aijp_run_ai_pipeline';

    /**
     * Custom schedule name.
     */
    public const SCHEDULE =
        'aijp_every_five_minutes';

    /**
     * Register cron schedules and action hooks.
     *
     * @return void
     */
    public function register(): void
    {
        add_filter(
            'cron_schedules',
            [
                $this,
                'register_schedules',
            ]
        );

        add_action(
            self::IMPORT_HOOK,
            [
                $this,
                'run_automatic_import',
            ]
        );

        /*
         * The actual AI pipeline callback is registered
         * in ai-job-pipeline.php.
         *
         * We do not register a second callback here to avoid
         * running the same batch twice.
         */
    }

    /**
     * Register custom cron schedules.
     *
     * @param array<string,array<string,mixed>> $schedules
     *
     * @return array<string,array<string,mixed>>
     */
    public function register_schedules(
        array $schedules
    ): array {
        if (
            !isset(
                $schedules[
                    self::SCHEDULE
                ]
            )
        ) {
            $minutes = max(
                5,
                min(
                    1440,
                    absint(
                        AIJP_Settings::get(
                            'cron_interval',
                            5
                        )
                    )
                )
            );

            $schedules[
                self::SCHEDULE
            ] = [
                'interval' => $minutes * MINUTE_IN_SECONDS,

                'display' => sprintf(
                    /* translators: %d: interval in minutes. */
                    __(
                        'Every %d minutes',
                        'ai-job-pipeline'
                    ),
                    $minutes
                ),
            ];
        }

        return $schedules;
    }

    /**
     * Schedule plugin events.
     *
     * @return void
     */
    public function schedule(): void
    {
        /*
         * Automatic RSS/job import.
         */
        if (
            !wp_next_scheduled(
                self::IMPORT_HOOK
            )
        ) {
            wp_schedule_event(
                time() + MINUTE_IN_SECONDS,
                self::SCHEDULE,
                self::IMPORT_HOOK
            );

            AIJP_Logger::info(
                'Scheduled automatic job import cron event.'
            );
        }

        /*
         * Automatic AI processing.
         *
         * Start slightly later than the import event so that
         * newly imported jobs have a chance to be committed
         * before AI processing begins.
         */
        if (
            !wp_next_scheduled(
                self::AI_PIPELINE_HOOK
            )
        ) {
            wp_schedule_event(
                time() + (2 * MINUTE_IN_SECONDS),
                self::SCHEDULE,
                self::AI_PIPELINE_HOOK,
                [
                    5,
                ]
            );

            AIJP_Logger::info(
                'Scheduled automatic AI pipeline cron event.'
            );
        }
    }

    /**
     * Unschedule all plugin events.
     *
     * @return void
     */
    public function unschedule(): void
    {
        $this->unschedule_hook(
            self::IMPORT_HOOK
        );

        $this->unschedule_hook(
            self::AI_PIPELINE_HOOK
        );

        AIJP_Logger::info(
            'Unscheduled plugin cron events.'
        );
    }

    /**
     * Remove every scheduled occurrence of a hook.
     *
     * @param string $hook Cron hook.
     *
     * @return void
     */
    private function unschedule_hook(
        string $hook
    ): void {
        $timestamp = wp_next_scheduled(
            $hook
        );

        while (
            $timestamp !== false
        ) {
            wp_unschedule_event(
                $timestamp,
                $hook
            );

            $timestamp = wp_next_scheduled(
                $hook
            );
        }

        /*
         * Also clear scheduled events with arguments.
         *
         * This is important for the AI pipeline because
         * it is scheduled with the batch size argument.
         */
        wp_clear_scheduled_hook(
            $hook
        );
    }

    /**
     * Run automatic job import.
     *
     * @return void
     */
    public function run_automatic_import(): void
    {
        AIJP_Logger::info(
            'Automatic job import started.'
        );

        try {
            $importer = new AIJP_Job_Importer();

            $result = $importer->import_enabled_sources();

            if (
                is_wp_error(
                    $result
                )
            ) {
                AIJP_Logger::error(
                    sprintf(
                        'Automatic job import failed: %s',
                        $result->get_error_message()
                    )
                );

                return;
            }

            $sources = absint(
                $result['sources']
                ?? 0
            );

            $found = absint(
                $result['found']
                ?? 0
            );

            $added = absint(
                $result['added']
                ?? 0
            );

            $duplicates = absint(
                $result['duplicates']
                ?? 0
            );

            $errors = absint(
                $result['errors']
                ?? 0
            );

            AIJP_Logger::info(
                sprintf(
                    'Automatic job import finished: sources %d, found %d, added %d, duplicates %d, errors %d.',
                    $sources,
                    $found,
                    $added,
                    $duplicates,
                    $errors
                )
            );
        } catch (Throwable $e) {
            AIJP_Logger::error(
                sprintf(
                    'Automatic job import crashed: %s',
                    $e->getMessage()
                )
            );
        }
    }
}
