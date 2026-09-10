<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Job status definitions and transition rules.
 */
class AIJP_Job_Status {

    public const FOUND = 'found';

    public const ANALYZED = 'analyzed';

    public const SKIPPED = 'skipped';

    public const PROPOSAL_SENT = 'proposal_sent';

    public const REJECTED = 'rejected';

    public const ACCEPTED = 'accepted';

    public const IN_PROGRESS = 'in_progress';

    public const DONE = 'done';

    public const DONE_BY_OTHER = 'done_by_other';

    public const PAID = 'paid';

    public const ANALYSIS_FAILED = 'analysis_failed';

    /**
     * Return all supported statuses.
     *
     * @return array<string>
     */
    public static function all(): array {
        return array(
            self::FOUND,
            self::ANALYZED,
            self::SKIPPED,
            self::PROPOSAL_SENT,
            self::REJECTED,
            self::ACCEPTED,
            self::IN_PROGRESS,
            self::DONE,
            self::DONE_BY_OTHER,
            self::PAID,
            self::ANALYSIS_FAILED,
        );
    }

    /**
     * Check whether a status is valid.
     *
     * @param string $status Status to check.
     * @return bool
     */
    public static function is_valid( string $status ): bool {
        return in_array( $status, self::all(), true );
    }

    /**
     * Return statuses that may follow the given status.
     *
     * @param string $status Current status.
     * @return array<string>
     */
    public static function allowed_transitions( string $status ): array {
        $transitions = array(
            self::FOUND => array(
                self::ANALYZED,
                self::SKIPPED,
                self::ANALYSIS_FAILED,
            ),

            self::ANALYZED => array(
                self::PROPOSAL_SENT,
                self::SKIPPED,
                self::ANALYSIS_FAILED,
            ),

            self::ANALYSIS_FAILED => array(
                self::ANALYZED,
                self::SKIPPED,
            ),

            self::PROPOSAL_SENT => array(
                self::REJECTED,
                self::ACCEPTED,
            ),

            self::ACCEPTED => array(
                self::IN_PROGRESS,
            ),

            self::IN_PROGRESS => array(
                self::DONE,
                self::DONE_BY_OTHER,
            ),

            self::DONE => array(
                self::PAID,
            ),

            self::SKIPPED => array(),

            self::REJECTED => array(),

            self::DONE_BY_OTHER => array(),

            self::PAID => array(),
        );

        return $transitions[ $status ] ?? array();
    }

    /**
     * Check whether a transition from one status to another is allowed.
     *
     * @param string $from Current status.
     * @param string $to   New status.
     * @return bool
     */
    public static function can_transition( string $from, string $to ): bool {
        if ( ! self::is_valid( $from ) || ! self::is_valid( $to ) ) {
            return false;
        }

        if ( $from === $to ) {
            return true;
        }

        return in_array(
            $to,
            self::allowed_transitions( $from ),
            true
        );
    }

    /**
     * Return a human-readable label for a status.
     *
     * @param string $status Status.
     * @return string
     */
    public static function label( string $status ): string {
        $labels = array(
            self::FOUND          => __( 'Found', 'ai-job-pipeline' ),
            self::ANALYZED       => __( 'Analyzed', 'ai-job-pipeline' ),
            self::SKIPPED        => __( 'Skipped', 'ai-job-pipeline' ),
            self::PROPOSAL_SENT  => __( 'Proposal sent', 'ai-job-pipeline' ),
            self::REJECTED       => __( 'Rejected', 'ai-job-pipeline' ),
            self::ACCEPTED       => __( 'Accepted', 'ai-job-pipeline' ),
            self::IN_PROGRESS    => __( 'In progress', 'ai-job-pipeline' ),
            self::DONE           => __( 'Done', 'ai-job-pipeline' ),
            self::DONE_BY_OTHER  => __( 'Done by other', 'ai-job-pipeline' ),
            self::PAID           => __( 'Paid', 'ai-job-pipeline' ),
            self::ANALYSIS_FAILED => __( 'Analysis failed', 'ai-job-pipeline' ),
        );

        return $labels[ $status ] ?? $status;
    }
}