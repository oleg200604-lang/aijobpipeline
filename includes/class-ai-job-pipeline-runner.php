<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runs AI processing for jobs waiting for analysis.
 *
 * Responsibilities:
 * - acquire a single-runner lock;
 * - load jobs through the repository;
 * - process jobs one by one;
 * - collect batch statistics;
 * - isolate individual job failures;
 * - release the lock reliably.
 *
 * This class does not:
 * - build AI prompts;
 * - call the AI provider directly;
 * - manipulate database tables directly;
 * - decide whether a job should be accepted.
 */
class AIJP_AI_Job_Pipeline_Runner
{
    /**
     * WordPress option used as the runner lock.
     */
    private const LOCK_KEY =
        'aijp_ai_pipeline_lock';

    /**
     * Lock lifetime.
     *
     * Long enough for a normal batch, but short enough to recover
     * from a PHP process that died unexpectedly.
     */
    private const LOCK_TTL =
        300;

    /**
     * Default number of jobs processed per run.
     */
    private const DEFAULT_BATCH_SIZE =
        5;

    /**
     * Absolute maximum number of jobs in one run.
     */
    private const MAX_BATCH_SIZE =
        50;

    /**
     * Job processor.
     *
     * @var AIJP_AI_Job_Processor
     */
    private AIJP_AI_Job_Processor $processor;

    /**
     * Job repository.
     *
     * @var AIJP_Job_Repository
     */
    private AIJP_Job_Repository $jobs;

    /**
     * Unique lock owner token for this runner instance.
     *
     * @var string
     */
    private string $lock_token = '';

    /**
     * Constructor.
     *
     * @param AIJP_AI_Job_Processor|null $processor Processor.
     * @param AIJP_Job_Repository|null   $jobs      Repository.
     */
    public function __construct(
        ?AIJP_AI_Job_Processor $processor = null,
        ?AIJP_Job_Repository $jobs = null
    ) {
        $this->processor =
            $processor
            ?? new AIJP_AI_Job_Processor();

        $this->jobs =
            $jobs
            ?? new AIJP_Job_Repository();
    }

    /**
     * Run the AI pipeline.
     *
     * @param int|null $batch_size Number of jobs to process.
     *
     * @return array<string,mixed>
     */
    public function run(
        ?int $batch_size = null
    ): array {
        $started_at =
            microtime(true);

        $batch_size =
            $this->get_batch_size(
                $batch_size
            );

        $result = [
            'success' => true,

            'locked' => false,

            'batch_size' =>
                $batch_size,

            'found' => 0,

            'processed' => 0,

            'analyzed' => 0,

            'skipped' => 0,

            'failed' => 0,

            'errors' => [],

            'duration' => 0,
        ];

        /*
         * ---------------------------------------------------------
         * Acquire lock.
         * ---------------------------------------------------------
         */
        if (
            !$this->acquire_lock()
        ) {
            $result['success'] =
                false;

            $result['locked'] =
                true;

            $result['duration'] =
                round(
                    microtime(true) -
                    $started_at,
                    2
                );

            $this->log_info(
                'AI pipeline skipped because another runner is active.'
            );

            return $result;
        }

        $this->log_info(
            sprintf(
                'AI pipeline started. Batch size: %d.',
                $batch_size
            )
        );

        try {
            /*
             * -----------------------------------------------------
             * Load jobs through repository.
             * -----------------------------------------------------
             */
            $jobs =
                $this->get_jobs(
                    $batch_size
                );

            $result['found'] =
                count($jobs);

            if (
                empty($jobs)
            ) {
                $this->log_info(
                    'AI pipeline finished: no jobs waiting for analysis.'
                );

                return $result;
            }

            /*
             * -----------------------------------------------------
             * Process jobs independently.
             * -----------------------------------------------------
             *
             * One broken job must never abort the entire batch.
             */
            foreach (
                $jobs as $job
            ) {
                $this->refresh_lock();

                $job_id =
                    $this->extract_job_id(
                        $job
                    );

                if (
                    $job_id <= 0
                ) {
                    $result['failed']++;

                    $result['errors'][] = [
                        'job_id' => 0,

                        'error' =>
                            __(
                                'Repository returned a job with an invalid ID.',
                                'ai-job-pipeline'
                            ),
                    ];

                    $this->log_error(
                        'AI pipeline received a job with an invalid ID.'
                    );

                    continue;
                }

                try {
                    $processing_result =
                        $this->processor->process(
                            $job_id
                        );

                    if (
                        is_wp_error(
                            $processing_result
                        )
                    ) {
                        $result['failed']++;

                        $result['errors'][] = [
                            'job_id' =>
                                $job_id,

                            'code' =>
                                $processing_result
                                    ->get_error_code(),

                            'error' =>
                                $processing_result
                                    ->get_error_message(),
                        ];

                        $this->log_error(
                            sprintf(
                                'AI pipeline failed for job #%d: %s',
                                $job_id,
                                $processing_result
                                    ->get_error_message()
                            )
                        );

                        continue;
                    }

                    if (
                        !is_array(
                            $processing_result
                        )
                    ) {
                        $result['failed']++;

                        $result['errors'][] = [
                            'job_id' =>
                                $job_id,

                            'error' =>
                                __(
                                    'AI processor returned an invalid result.',
                                    'ai-job-pipeline'
                                ),
                        ];

                        $this->log_error(
                            sprintf(
                                'AI processor returned an invalid result for job #%d.',
                                $job_id
                            )
                        );

                        continue;
                    }

                    $result['processed']++;

                    $status =
                        $this->get_result_status(
                            $processing_result
                        );

                    switch (
                        $status
                    ) {
                        case AIJP_Job_Status::ANALYZED:
                            $result['analyzed']++;
                            break;

                        case AIJP_Job_Status::SKIPPED:
                            $result['skipped']++;
                            break;

                        case AIJP_Job_Status::ANALYSIS_FAILED:
                            /*
                             * The processor normally returns WP_Error
                             * for technical/validation failures. This
                             * branch exists so the runner remains
                             * correct if the processor explicitly
                             * records analysis_failed in the future.
                             */
                            $result['failed']++;

                            $result['errors'][] = [
                                'job_id' =>
                                    $job_id,

                                'error' =>
                                    __(
                                        'AI analysis failed.',
                                        'ai-job-pipeline'
                                    ),
                            ];

                            break;

                        default:
                            /*
                             * A successful processor execution must
                             * result in a known AI workflow status.
                             */
                            $result['failed']++;

                            $result['errors'][] = [
                                'job_id' =>
                                    $job_id,

                                'error' =>
                                    sprintf(
                                        /* translators: %s: unexpected status. */
                                        __(
                                            'Unexpected AI pipeline status: %s.',
                                            'ai-job-pipeline'
                                        ),
                                        $status !== ''
                                            ? $status
                                            : 'empty'
                                    ),
                            ];

                            $this->log_error(
                                sprintf(
                                    'AI pipeline processed job #%d but returned unexpected status "%s".',
                                    $job_id,
                                    $status !== ''
                                        ? $status
                                        : 'empty'
                                )
                            );

                            break;
                    }
                } catch (
                    Throwable $e
                ) {
                    $result['failed']++;

                    $result['errors'][] = [
                        'job_id' =>
                            $job_id,

                        'error' =>
                            $e->getMessage(),
                    ];

                    $this->log_error(
                        sprintf(
                            'Unexpected AI pipeline exception for job #%d: %s',
                            $job_id,
                            $e->getMessage()
                        )
                    );

                    /*
                     * Continue processing the remaining jobs.
                     */
                    continue;
                }
            }
        } catch (
            Throwable $e
        ) {
            $result['success'] =
                false;

            $result['errors'][] = [
                'job_id' => 0,

                'error' =>
                    $e->getMessage(),
            ];

            $this->log_error(
                sprintf(
                    'AI pipeline runner failed: %s',
                    $e->getMessage()
                )
            );
        } finally {
            $this->release_lock();

            $result['duration'] =
                round(
                    microtime(true) -
                    $started_at,
                    2
                );

            $this->log_info(
                sprintf(
                    'AI pipeline finished: found %d, processed %d, analyzed %d, skipped %d, failed %d, duration %ss.',
                    $result['found'],
                    $result['processed'],
                    $result['analyzed'],
                    $result['skipped'],
                    $result['failed'],
                    $result['duration']
                )
            );
        }

        return $result;
    }

    /**
     * Get jobs waiting for AI processing.
     *
     * The repository is the only data-access layer used here.
     *
     * @param int $limit Maximum number of jobs.
     *
     * @return array<int,object>
     */
    private function get_jobs(
        int $limit
    ): array {
        $limit =
            max(
                1,
                min(
                    $limit,
                    self::MAX_BATCH_SIZE
                )
            );

        /*
         * Preferred method: explicitly represents the AI queue.
         */
        if (
            method_exists(
                $this->jobs,
                'get_pending_ai_jobs'
            )
        ) {
            $jobs =
                $this->jobs->get_pending_ai_jobs(
                    $limit
                );

            if (
                is_array($jobs)
            ) {
                return $jobs;
            }
        }

        /*
         * Compatibility path for repositories exposing only
         * get_by_status().
         */
        if (
            method_exists(
                $this->jobs,
                'get_by_status'
            )
        ) {
            $jobs =
                $this->jobs->get_by_status(
                    AIJP_Job_Status::FOUND,
                    $limit
                );

            if (
                is_array($jobs)
            ) {
                return $jobs;
            }
        }

        /*
         * Do NOT fall back to direct SQL here.
         *
         * The repository is responsible for the database schema.
         * A SQL fallback would recreate the exact coupling we are
         * removing from the plugin.
         */
        $this->log_error(
            'AI job repository does not provide a pending-job query method.'
        );

        return [];
    }

    /**
     * Extract job ID from repository result.
     *
     * @param mixed $job Repository result.
     *
     * @return int
     */
    private function extract_job_id(
        mixed $job
    ): int {
        if (
            is_object($job)
        ) {
            return absint(
                $job->id
                ?? 0
            );
        }

        if (
            is_array($job)
        ) {
            return absint(
                $job['id']
                ?? 0
            );
        }

        return 0;
    }

    /**
     * Extract resulting status from processor result.
     *
     * @param array<string,mixed> $result Processor result.
     *
     * @return string
     */
    private function get_result_status(
        array $result
    ): string {
        $status =
            $result['status']
            ?? '';

        if (
            !is_scalar($status)
        ) {
            return '';
        }

        return sanitize_key(
            (string) $status
        );
    }

    /**
     * Acquire runner lock.
     *
     * add_option() is atomic at the WordPress database level,
     * so it is preferable to a get_option() + update_option()
     * check-and-set sequence.
     *
     * @return bool
     */
    private function acquire_lock(): bool
    {
        $now =
            time();

        $existing =
            get_option(
                self::LOCK_KEY
            );

        /*
         * Existing active lock.
         */
        if (
            is_array($existing) &&
            !empty($existing['expires']) &&
            (int) $existing['expires'] > $now
        ) {
            return false;
        }

        /*
         * Generate a unique owner token.
         *
         * The token is used when releasing the lock so that an old
         * runner cannot accidentally delete a newer runner's lock.
         */
        $this->lock_token =
            wp_generate_uuid4();

        /*
         * Remove an expired lock.
         *
         * add_option() below remains the actual acquisition attempt.
         */
        if (
            is_array($existing)
        ) {
            delete_option(
                self::LOCK_KEY
            );
        }

        $lock = [
            'token' =>
                $this->lock_token,

            'created' =>
                $now,

            'expires' =>
                $now +
                self::LOCK_TTL,

            'pid' =>
                function_exists('getmypid')
                    ? (int) getmypid()
                    : 0,
        ];

        /*
         * add_option() returns false if another process inserted
         * the option first.
         */
        return add_option(
            self::LOCK_KEY,
            $lock,
            '',
            false
        );
    }

    /**
     * Refresh lock expiration.
     *
     * Only the current owner may refresh the lock.
     *
     * @return void
     */
    private function refresh_lock(): void
    {
        if (
            $this->lock_token === ''
        ) {
            return;
        }

        $lock =
            get_option(
                self::LOCK_KEY
            );

        if (
            !is_array($lock)
        ) {
            return;
        }

        if (
            ($lock['token'] ?? '')
            !== $this->lock_token
        ) {
            return;
        }

        $lock['expires'] =
            time() +
            self::LOCK_TTL;

        update_option(
            self::LOCK_KEY,
            $lock,
            false
        );
    }

    /**
     * Release runner lock.
     *
     * Never delete another runner's lock.
     *
     * @return void
     */
    private function release_lock(): void
    {
        if (
            $this->lock_token === ''
        ) {
            return;
        }

        $lock =
            get_option(
                self::LOCK_KEY
            );

        if (
            !is_array($lock)
        ) {
            return;
        }

        if (
            ($lock['token'] ?? '')
            !== $this->lock_token
        ) {
            return;
        }

        delete_option(
            self::LOCK_KEY
        );

        $this->lock_token =
            '';
    }

    /**
     * Normalize requested batch size.
     *
     * @param int|null $batch_size Requested size.
     *
     * @return int
     */
    private function get_batch_size(
        ?int $batch_size
    ): int {
        if (
            $batch_size === null ||
            $batch_size <= 0
        ) {
            $batch_size =
                self::DEFAULT_BATCH_SIZE;
        }

        $batch_size =
            absint(
                $batch_size
            );

        if (
            $batch_size <= 0
        ) {
            $batch_size =
                self::DEFAULT_BATCH_SIZE;
        }

        $batch_size =
            min(
                $batch_size,
                self::MAX_BATCH_SIZE
            );

        $batch_size =
            (int) apply_filters(
                'aijp_ai_pipeline_batch_size',
                $batch_size
            );

        /*
         * A filter must not be able to bypass the hard upper limit.
         */
        return max(
            1,
            min(
                $batch_size,
                self::MAX_BATCH_SIZE
            )
        );
    }

    /**
     * Log an informational message.
     *
     * @param string $message Message.
     *
     * @return void
     */
    private function log_info(
        string $message
    ): void {
        if (
            class_exists(
                'AIJP_Logger'
            ) &&
            method_exists(
                'AIJP_Logger',
                'info'
            )
        ) {
            AIJP_Logger::info(
                $message
            );

            return;
        }

        if (
            defined('WP_DEBUG') &&
            WP_DEBUG
        ) {
            error_log(
                '[AI Job Pipeline] ' .
                $message
            );
        }
    }

    /**
     * Log an error message.
     *
     * @param string $message Message.
     *
     * @return void
     */
    private function log_error(
        string $message
    ): void {
        if (
            class_exists(
                'AIJP_Logger'
            ) &&
            method_exists(
                'AIJP_Logger',
                'error'
            )
        ) {
            AIJP_Logger::error(
                $message
            );

            return;
        }

        if (
            defined('WP_DEBUG') &&
            WP_DEBUG
        ) {
            error_log(
                '[AI Job Pipeline] ' .
                $message
            );
        }
    }
}