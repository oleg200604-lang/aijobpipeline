<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Proposal service.
 *
 * Coordinates:
 *
 * - building normalized job/analysis context (via AIJP_AI_Agent_Context);
 * - building the proposal prompt (via AIJP_Proposal_Prompts);
 * - requesting a draft from the AI provider (via AIJP_AI_Client);
 * - persisting the draft (via AIJP_Proposal_Repository).
 *
 * This class does not:
 *
 * - render admin UI;
 * - send anything to a client;
 * - change job status directly (the caller decides that, since a
 *   generated draft does not by itself mean it was sent).
 *
 * Mirrors the layering of AIJP_AI / AIJP_AI_Agent for the analysis
 * operation, so the codebase has one consistent pattern per AI
 * operation instead of ad hoc admin-page logic.
 */
class AIJP_Proposal_Service
{
    /**
     * AI operation name, used for AI usage/cost accounting.
     */
    private const OPERATION = 'proposal_generation';

    /**
     * AI client.
     *
     * @var AIJP_AI_Client
     */
    private AIJP_AI_Client $client;

    public function __construct()
    {
        $this->client = new AIJP_AI_Client();
    }

    /**
     * Generate and store a new proposal draft for a job.
     *
     * @param int    $job_id       Job ID.
     * @param string $lang_override Optional language override; falls
     *                               back to the job's own language.
     *
     * @return int|WP_Error Proposal ID, or error.
     */
    public function generate(
        int $job_id,
        string $lang_override = ''
    ): int|WP_Error {
        $context_builder = new AIJP_AI_Agent_Context();

        $context = $context_builder->build($job_id);

        if (is_wp_error($context)) {
            return $context;
        }

        $job = $context['job'];
        $analysis = $context['previous_analysis'] ?? [];

        $lang = $lang_override !== ''
            ? sanitize_key($lang_override)
            : (string) ($job['lang'] ?? 'en');

        if ($lang === '') {
            $lang = 'en';
        }

        $prompt = AIJP_Proposal_Prompts::build(
            $job,
            is_array($analysis) ? $analysis : [],
            $lang
        );

        $response = $this->client->request(
            $prompt,
            [
                'job_id' => $job_id,
                'operation' => self::OPERATION,
            ]
        );

        if (is_wp_error($response)) {
            AIJP_Logger::error(
                sprintf(
                    'Proposal generation failed for job #%d: %s',
                    $job_id,
                    $response->get_error_message()
                )
            );

            return $response;
        }

        $text = trim((string) ($response['content'] ?? ''));

        if ($text === '') {
            return new WP_Error(
                'aijp_empty_proposal',
                __(
                    'AI returned an empty proposal draft.',
                    'ai-job-pipeline'
                )
            );
        }

        $proposal_id = AIJP_Proposal_Repository::insert_draft(
            $job_id,
            [
                'lang' => $lang,
                'text' => $text,
                'source' => 'ai',
            ],
            [
                'provider' => $response['provider'] ?? '',
                'model' => $response['model'] ?? '',
                'prompt_version' => AIJP_Proposal_Prompts::get_version(),
            ]
        );

        if ($proposal_id === false) {
            return new WP_Error(
                'aijp_proposal_save_failed',
                __(
                    'Could not save the generated proposal.',
                    'ai-job-pipeline'
                )
            );
        }

        return $proposal_id;
    }
}