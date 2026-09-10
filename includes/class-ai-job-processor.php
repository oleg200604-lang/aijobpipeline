<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI Job Processor.
 *
 * Coordinates analysis of one job.
 *
 * Responsibilities:
 *
 * - load the job;
 * - validate that the job may be analyzed;
 * - build normalized AI context;
 * - call the AI Agent;
 * - validate the Agent result;
 * - persist the analysis and AI metadata;
 * - transition the job to ANALYZED or SKIPPED;
 * - mark failed analyses as ANALYSIS_FAILED;
 * - log processing errors.
 *
 * This class does not:
 *
 * - communicate with OpenAI directly;
 * - build prompts;
 * - parse provider responses;
 * - manage cron locks;
 * - manage proposals, execution or payments.
 */
class AIJP_AI_Job_Processor
{
    /**
     * Job repository.
     *
     * @var AIJP_Job_Repository
     */
    private AIJP_Job_Repository $jobs;

    /**
     * AI Agent context builder.
     *
     * @var AIJP_AI_Agent_Context
     */
    private AIJP_AI_Agent_Context $context;

    /**
     * AI Agent.
     *
     * @var AIJP_AI_Agent
     */
    private AIJP_AI_Agent $agent;

    /**
     * Logger.
     *
     * @var AIJP_Logger|null
     */
    private ?AIJP_Logger $logger;

    /**
     * Constructor.
     *
     * @param AIJP_Job_Repository|null   $jobs    Job repository.
     * @param AIJP_AI_Agent_Context|null  $context AI context builder.
     * @param AIJP_AI_Agent|null         $agent   AI Agent.
     * @param AIJP_Logger|null           $logger  Logger.
     */
    public function __construct(
        ?AIJP_Job_Repository $jobs = null,
        ?AIJP_AI_Agent_Context $context = null,
        ?AIJP_AI_Agent $agent = null,
        ?AIJP_Logger $logger = null
    ) {
        $this->jobs =
            $jobs
            ?? new AIJP_Job_Repository();

        $this->context =
            $context
            ?? new AIJP_AI_Agent_Context();

        $this->agent =
            $agent
            ?? new AIJP_AI_Agent();

        $this->logger =
            $logger
            ?? new AIJP_Logger();
    }

    /**
     * Process one job.
     *
     * A job may normally be analyzed from FOUND.
     * ANALYSIS_FAILED is also accepted so failed jobs can be retried.
     *
     * @param int $job_id Job ID.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function process(int $job_id): array|WP_Error
    {
        $job_id = absint($job_id);

        if ($job_id <= 0) {
            return new WP_Error(
                'aijp_ai_invalid_job_id',
                __('Invalid job ID.', 'ai-job-pipeline')
            );
        }

        $job = $this->get_job($job_id);

        if (is_wp_error($job)) {
            return $job;
        }

        $current_status = $this->get_job_status($job);

        if (
            !in_array(
                $current_status,
                [
                    AIJP_Job_Status::FOUND,
                    AIJP_Job_Status::ANALYSIS_FAILED,
                ],
                true
            )
        ) {
            return new WP_Error(
                'aijp_ai_job_not_pending',
                sprintf(
                    __(
                        'Job #%d cannot be analyzed from status "%s".',
                        'ai-job-pipeline'
                    ),
                    $job_id,
                    $current_status
                ),
                [
                    'job_id' => $job_id,
                    'status' => $current_status,
                ]
            );
        }

        $this->log(
            'info',
            sprintf(
                'AI processing started for job #%d.',
                $job_id
            )
        );

        /*
         * Build context through the dedicated context builder.
         *
         * This prevents the processor from duplicating job normalization
         * logic and keeps the AI-facing data contract in one place.
         */
        $context = $this->context->build($job_id);

        if (is_wp_error($context)) {
            $this->handle_processing_error(
                $job_id,
                $context
            );

            return $context;
        }

        if (!is_array($context)) {
            $error = new WP_Error(
                'aijp_ai_invalid_context',
                __('AI Agent context is invalid.', 'ai-job-pipeline')
            );

            $this->handle_processing_error(
                $job_id,
                $error
            );

            return $error;
        }

        /*
         * Execute the complete AI analysis flow.
         *
         * AI Agent is responsible for:
         * - prompt generation;
         * - provider request;
         * - response parsing;
         * - validation;
         * - normalization.
         */
        $result = $this->agent->analyze($context);

        if (is_wp_error($result)) {
            $this->handle_processing_error(
                $job_id,
                $result
            );

            return $result;
        }

        if (!is_array($result)) {
            $error = new WP_Error(
                'aijp_ai_invalid_agent_result',
                __('AI Agent returned an invalid result.', 'ai-job-pipeline')
            );

            $this->handle_processing_error(
                $job_id,
                $error
            );

            return $error;
        }

        $analysis = $this->extract_analysis($result);

        if (is_wp_error($analysis)) {
            $this->handle_processing_error(
                $job_id,
                $analysis
            );

            return $analysis;
        }

        /*
         * The Agent normally already normalizes this contract.
         * The processor nevertheless performs a final boundary check so
         * malformed data can never be persisted as a successful analysis.
         */
        $validation = $this->validate_analysis_contract($analysis);

        if (is_wp_error($validation)) {
            $this->handle_processing_error(
                $job_id,
                $validation
            );

            return $validation;
        }

        $next_status = $this->determine_next_status(
            $analysis
        );

        /*
         * Persist all analysis-related data before changing the job state.
         *
         * If persistence fails, the job remains/reverts to
         * ANALYSIS_FAILED instead of being marked as successfully analyzed.
         */
        $save_result = $this->save_analysis(
            $job_id,
            $analysis,
            $result,
            $context
        );

        if (is_wp_error($save_result)) {
            $this->handle_processing_error(
                $job_id,
                $save_result
            );

            return $save_result;
        }

        /*
         * Transition only after the analysis has been successfully stored.
         */
        $status_result = $this->transition_status(
            $job_id,
            $current_status,
            $next_status
        );

        if (is_wp_error($status_result)) {
            $this->log(
                'error',
                sprintf(
                    'AI analysis was saved for job #%d, but status transition to "%s" failed: [%s] %s',
                    $job_id,
                    $next_status,
                    $status_result->get_error_code(),
                    $status_result->get_error_message()
                )
            );

            return $status_result;
        }

        $this->log(
            'info',
            sprintf(
                'AI processing completed for job #%d. Status: %s. Recommendation: %s.',
                $job_id,
                $next_status,
                $analysis['recommendation']
            )
        );

        return [
            'success' => true,
            'job_id' => $job_id,
            'previous_status' => $current_status,
            'status' => $next_status,
            'analysis' => $analysis,
            'provider' => $this->string_value(
                $result,
                'provider'
            ),
            'model' => $this->string_value(
                $result,
                'model'
            ),
            'usage' => $this->array_value(
                $result,
                'usage'
            ),
            'prompt_meta' => $this->array_value(
                $result,
                'prompt_meta'
            ),
            'context_meta' => $this->array_value(
                $result,
                'context_meta'
            ),
            'raw_response' => $this->string_value(
                $result,
                'raw_response'
            ),
        ];
    }

    /**
     * Compatibility alias.
     *
     * @param int $job_id Job ID.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function process_job(int $job_id): array|WP_Error
    {
        return $this->process($job_id);
    }

    /**
     * Load a job.
     *
     * @param int $job_id Job ID.
     *
     * @return array<string,mixed>|WP_Error
     */
    private function get_job(int $job_id): array|WP_Error
    {
        if (!method_exists($this->jobs, 'find')) {
            return new WP_Error(
                'aijp_ai_repository_find_missing',
                __(
                    'Job repository does not support finding jobs.',
                    'ai-job-pipeline'
                )
            );
        }

        $job = $this->jobs->find($job_id);

        if (is_wp_error($job)) {
            return $job;
        }

        if (is_object($job)) {
            $job = get_object_vars($job);
        }

        if (!is_array($job) || empty($job)) {
            return new WP_Error(
                'aijp_ai_job_not_found',
                sprintf(
                    __(
                        'Job #%d was not found.',
                        'ai-job-pipeline'
                    ),
                    $job_id
                )
            );
        }

        return $job;
    }

    /**
     * Extract analysis from Agent result.
     *
     * @param array<string,mixed> $result Agent result.
     *
     * @return array<string,mixed>|WP_Error
     */
    private function extract_analysis(array $result): array|WP_Error
    {
        if (
            !isset($result['analysis']) ||
            !is_array($result['analysis'])
        ) {
            return new WP_Error(
                'aijp_ai_missing_analysis',
                __(
                    'AI Agent did not return an analysis.',
                    'ai-job-pipeline'
                )
            );
        }

        return $result['analysis'];
    }

    /**
     * Validate the normalized analysis contract.
     *
     * @param array<string,mixed> $analysis Analysis.
     *
     * @return true|WP_Error
     */
    private function validate_analysis_contract(
        array $analysis
    ): true|WP_Error {
        $required = [
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

        foreach ($required as $field) {
            if (!array_key_exists($field, $analysis)) {
                return new WP_Error(
                    'aijp_ai_analysis_missing_field',
                    sprintf(
                        __(
                            'AI analysis is missing required field "%s".',
                            'ai-job-pipeline'
                        ),
                        $field
                    ),
                    [
                        'field' => $field,
                    ]
                );
            }
        }

        $summary = $this->string_value(
            $analysis,
            'summary'
        );

        if ($summary === '') {
            return new WP_Error(
                'aijp_ai_analysis_empty_summary',
                __(
                    'AI analysis summary cannot be empty.',
                    'ai-job-pipeline'
                )
            );
        }

        $category = $this->string_value(
            $analysis,
            'category'
        );

        if (
            !in_array(
                $category,
                [
                    'web',
                    'data',
                    'other',
                ],
                true
            )
        ) {
            return new WP_Error(
                'aijp_ai_analysis_invalid_category',
                sprintf(
                    __(
                        'Invalid AI analysis category: %s.',
                        'ai-job-pipeline'
                    ),
                    $category
                )
            );
        }

        $complexity = $analysis['complexity'];

        if (
            !is_numeric($complexity) ||
            (float) $complexity < 1 ||
            (float) $complexity > 5
        ) {
            return new WP_Error(
                'aijp_ai_analysis_invalid_complexity',
                __(
                    'AI analysis complexity must be between 1 and 5.',
                    'ai-job-pipeline'
                )
            );
        }

        $feasible = $this->string_value(
            $analysis,
            'ai_feasible'
        );

        if (
            !in_array(
                $feasible,
                [
                    'yes',
                    'partial',
                    'no',
                ],
                true
            )
        ) {
            return new WP_Error(
                'aijp_ai_analysis_invalid_feasibility',
                sprintf(
                    __(
                        'Invalid AI feasibility value: %s.',
                        'ai-job-pipeline'
                    ),
                    $feasible
                )
            );
        }

        $estimated_hours = $analysis['estimated_hours'];

        if (
            !is_numeric($estimated_hours) ||
            (float) $estimated_hours < 0
        ) {
            return new WP_Error(
                'aijp_ai_analysis_invalid_hours',
                __(
                    'Estimated hours must be a non-negative number.',
                    'ai-job-pipeline'
                )
            );
        }

        if (
            !is_array($analysis['plan'])
        ) {
            return new WP_Error(
                'aijp_ai_analysis_invalid_plan',
                __(
                    'AI analysis plan must be an array.',
                    'ai-job-pipeline'
                )
            );
        }

        if (
            !is_array($analysis['red_flags'])
        ) {
            return new WP_Error(
                'aijp_ai_analysis_invalid_red_flags',
                __(
                    'AI analysis red flags must be an array.',
                    'ai-job-pipeline'
                )
            );
        }

        $recommendation = $this->string_value(
            $analysis,
            'recommendation'
        );

        if (
            !in_array(
                $recommendation,
                [
                    'take',
                    'skip',
                ],
                true
            )
        ) {
            return new WP_Error(
                'aijp_ai_analysis_invalid_recommendation',
                sprintf(
                    __(
                        'Invalid AI recommendation: %s.',
                        'ai-job-pipeline'
                    ),
                    $recommendation
                )
            );
        }

        $confidence = $analysis['confidence'];

        if (
            !is_numeric($confidence) ||
            (float) $confidence < 0 ||
            (float) $confidence > 1
        ) {
            return new WP_Error(
                'aijp_ai_analysis_invalid_confidence',
                __(
                    'AI analysis confidence must be between 0 and 1.',
                    'ai-job-pipeline'
                )
            );
        }

        return true;
    }

    /**
     * Save analysis and its metadata.
     *
     * @param int                 $job_id  Job ID.
     * @param array<string,mixed> $analysis Analysis.
     * @param array<string,mixed> $result  Agent result.
     * @param array<string,mixed> $context AI context.
     *
     * @return true|WP_Error
     */
    private function save_analysis(
        int $job_id,
        array $analysis,
        array $result,
        array $context
    ): true|WP_Error {
        if (!method_exists($this->jobs, 'save_ai_analysis')) {
            return new WP_Error(
                'aijp_ai_repository_method_missing',
                __(
                    'Job repository does not support saving AI analysis.',
                    'ai-job-pipeline'
                )
            );
        }

        $prompt_meta = $this->array_value(
            $result,
            'prompt_meta'
        );

        $context_meta = $this->array_value(
            $result,
            'context_meta'
        );

        $usage = $this->array_value(
            $result,
            'usage'
        );

        $provider = $this->string_value(
            $result,
            'provider'
        );

        $model = $this->string_value(
            $result,
            'model'
        );

        $raw_response = $this->string_value(
            $result,
            'raw_response'
        );

        $prompt_version = $this->string_value(
            $prompt_meta,
            'version'
        );

        if ($prompt_version === '') {
            $prompt_version = AIJP_AI_Prompts::get_version();
        }

        $prompt_type = $this->string_value(
            $prompt_meta,
            'type'
        );

        if ($prompt_type === '') {
            $prompt_type = 'job_analysis';
        }

        $context_version = $this->string_value(
            $context_meta,
            'context_version'
        );

        if ($context_version === '') {
            $context_version = $this->string_value(
                $context,
                'metadata'
            );

            /*
             * The previous extraction may not contain nested metadata
             * as a scalar. Prefer the actual context value below.
             */
            if ($context_version === '') {
                $metadata = $this->array_value(
                    $context,
                    'metadata'
                );

                $context_version = $this->string_value(
                    $metadata,
                    'context_version'
                );
            }
        }

        $metadata = [
            'provider' => $provider,
            'model' => $model,
            'prompt_version' => $prompt_version,
            'prompt_type' => $prompt_type,
            'context_version' => $context_version,
            'raw_response' => $raw_response,
            'usage' => $usage,
            'prompt_meta' => $prompt_meta,
            'context_meta' => $context_meta,
        ];

        /*
         * The repository owns DB-specific persistence.
         * The processor passes normalized analysis + metadata and does not
         * know the physical structure of the analyses table.
         */
        $saved = $this->jobs->save_ai_analysis(
            $job_id,
            $analysis,
            $metadata
        );

        if (is_wp_error($saved)) {
            return $saved;
        }

        if ($saved === false) {
            return new WP_Error(
                'aijp_ai_analysis_save_failed',
                __(
                    'Failed to save AI analysis.',
                    'ai-job-pipeline'
                )
            );
        }

        return true;
    }

    /**
     * Determine the next job status from AI recommendation.
     *
     * take -> ANALYZED
     * skip -> SKIPPED
     *
     * @param array<string,mixed> $analysis Analysis.
     *
     * @return string
     */
    private function determine_next_status(
        array $analysis
    ): string {
        $recommendation = $this->string_value(
            $analysis,
            'recommendation'
        );

        if ($recommendation === 'take') {
            return AIJP_Job_Status::ANALYZED;
        }

        return AIJP_Job_Status::SKIPPED;
    }

    /**
     * Transition job status.
     *
     * @param int    $job_id         Job ID.
     * @param string $current_status Current status.
     * @param string $next_status    Target status.
     *
     * @return true|WP_Error
     */
    private function transition_status(
        int $job_id,
        string $current_status,
        string $next_status
    ): true|WP_Error {
        if (!AIJP_Job_Status::can_transition(
            $current_status,
            $next_status
        )) {
            return new WP_Error(
                'aijp_ai_invalid_status_transition',
                sprintf(
                    __(
                        'Invalid job status transition: %1$s → %2$s.',
                        'ai-job-pipeline'
                    ),
                    $current_status,
                    $next_status
                ),
                [
                    'job_id' => $job_id,
                    'from' => $current_status,
                    'to' => $next_status,
                ]
            );
        }

        if (!method_exists($this->jobs, 'update_status')) {
            return new WP_Error(
                'aijp_ai_status_method_missing',
                __(
                    'Job repository does not support status updates.',
                    'ai-job-pipeline'
                )
            );
        }

        $updated = $this->jobs->update_status(
            $job_id,
            $next_status
        );

        if (is_wp_error($updated)) {
            return $updated;
        }

        if ($updated === false) {
            return new WP_Error(
                'aijp_ai_status_update_failed',
                sprintf(
                    __(
                        'Failed to transition job #%d to "%s".',
                        'ai-job-pipeline'
                    ),
                    $job_id,
                    $next_status
                )
            );
        }

        return true;
    }

    /**
     * Mark a failed analysis.
     *
     * @param int      $job_id Job ID.
     * @param WP_Error $error  Processing error.
     *
     * @return void
     */
    private function handle_processing_error(
        int $job_id,
        WP_Error $error
    ): void {
        $job = $this->get_job($job_id);

        if (!is_wp_error($job) && is_array($job)) {
            $current_status = $this->get_job_status($job);

            if (
                AIJP_Job_Status::can_transition(
                    $current_status,
                    AIJP_Job_Status::ANALYSIS_FAILED
                )
            ) {
                $this->update_status_safely(
                    $job_id,
                    AIJP_Job_Status::ANALYSIS_FAILED
                );
            }
        }

        $this->log(
            'error',
            sprintf(
                'AI processing failed for job #%d: [%s] %s',
                $job_id,
                $error->get_error_code(),
                $error->get_error_message()
            )
        );
    }

    /**
     * Safely update a job status without replacing the original error.
     *
     * @param int    $job_id Job ID.
     * @param string $status Status.
     *
     * @return bool
     */
    private function update_status_safely(
        int $job_id,
        string $status
    ): bool {
        if (!method_exists($this->jobs, 'update_status')) {
            return false;
        }

        $result = $this->jobs->update_status(
            $job_id,
            $status
        );

        return !is_wp_error($result) && $result !== false;
    }

    /**
     * Get the current job status.
     *
     * @param array<string,mixed> $job Job.
     *
     * @return string
     */
    private function get_job_status(array $job): string
    {
        return sanitize_key(
            $this->string_value(
                $job,
                'status'
            )
        );
    }

    /**
     * Log a message.
     *
     * @param string $level   Log level.
     * @param string $message Message.
     *
     * @return void
     */
    private function log(
        string $level,
        string $message
    ): void {
        if ($this->logger === null) {
            return;
        }

        if (!method_exists($this->logger, $level)) {
            return;
        }

        $this->logger->{$level}(
            $message
        );
    }

    /**
     * Safely extract a string.
     *
     * @param array<string,mixed> $data Data.
     * @param string              $key  Key.
     *
     * @return string
     */
    private function string_value(
        array $data,
        string $key
    ): string {
        if (!array_key_exists($key, $data)) {
            return '';
        }

        $value = $data[$key];

        if (
            $value === null ||
            is_array($value) ||
            is_object($value)
        ) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Safely extract an array.
     *
     * @param array<string,mixed> $data Data.
     * @param string              $key  Key.
     *
     * @return array<string,mixed>
     */
    private function array_value(
        array $data,
        string $key
    ): array {
        if (
            !isset($data[$key]) ||
            !is_array($data[$key])
        ) {
            return [];
        }

        return $data[$key];
    }
}
