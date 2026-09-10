<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Proposal repository.
 *
 * Single responsibility:
 * - read proposals for a job;
 * - create a new proposal draft;
 * - update proposal text (manual edit before sending);
 * - mark a proposal as sent;
 * - record a client reply.
 *
 * Database column names used here are canonical:
 *
 * job_id
 * lang
 * text
 * status
 * source
 * provider
 * model
 * prompt_version
 * sent_at
 * client_reply
 * client_replied_at
 * created_at
 * updated_at
 *
 * A job may have several proposal rows over time (e.g. regenerated
 * after being edited or after the first draft was skipped); the most
 * recent row is what the admin UI treats as "current".
 */
class AIJP_Proposal_Repository
{
    /**
     * Proposal status: freshly generated, not sent yet.
     */
    public const STATUS_DRAFT = 'draft';

    /**
     * Proposal status: sent to the client by a human.
     */
    public const STATUS_SENT = 'sent';

    /**
     * Get the most recent proposal for a job, if any.
     *
     * @param int $job_id Job ID.
     * @return object|null
     */
    public static function get_latest_for_job(int $job_id)
    {
        global $wpdb;

        if ($job_id <= 0) {
            return null;
        }

        $table = AIJP_Database::table('proposals');

        return $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT *
                FROM {$table}
                WHERE job_id = %d
                ORDER BY id DESC
                LIMIT 1
                ",
                $job_id
            )
        );
    }

    /**
     * Get all proposals for a job, most recent first.
     *
     * @param int $job_id Job ID.
     * @return array<object>
     */
    public static function get_all_for_job(int $job_id): array
    {
        global $wpdb;

        if ($job_id <= 0) {
            return [];
        }

        $table = AIJP_Database::table('proposals');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT *
                FROM {$table}
                WHERE job_id = %d
                ORDER BY id DESC
                ",
                $job_id
            )
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Get one proposal by ID.
     *
     * @param int $id Proposal ID.
     * @return object|null
     */
    public static function get(int $id)
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $table = AIJP_Database::table('proposals');

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
                $id
            )
        );
    }

    /**
     * Insert a new proposal draft.
     *
     * @param int                  $job_id   Job ID.
     * @param array<string,mixed>  $data     Proposal data (lang, text).
     * @param array<string,mixed>  $metadata Provider/model/prompt metadata.
     * @return int|false Proposal ID or false.
     */
    public static function insert_draft(
        int $job_id,
        array $data,
        array $metadata = []
    ) {
        global $wpdb;

        if ($job_id <= 0) {
            return false;
        }

        $text = isset($data['text'])
            ? sanitize_textarea_field((string) $data['text'])
            : '';

        if ($text === '') {
            return false;
        }

        $table = AIJP_Database::table('proposals');

        $inserted = $wpdb->insert(
            $table,
            [
                'job_id' => $job_id,

                'lang' => isset($data['lang'])
                    ? sanitize_key((string) $data['lang'])
                    : null,

                'text' => $text,

                'status' => self::STATUS_DRAFT,

                'source' => isset($data['source'])
                    ? sanitize_key((string) $data['source'])
                    : 'ai',

                'provider' => isset($metadata['provider'])
                    ? sanitize_text_field((string) $metadata['provider'])
                    : null,

                'model' => isset($metadata['model'])
                    ? sanitize_text_field((string) $metadata['model'])
                    : null,

                'prompt_version' => isset($metadata['prompt_version'])
                    ? sanitize_text_field((string) $metadata['prompt_version'])
                    : null,
            ],
            [
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            ]
        );

        if ($inserted === false) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Update the text of an existing proposal (manual edit before
     * sending). This does not change the proposal's status.
     *
     * @param int    $id   Proposal ID.
     * @param string $text New text.
     * @return bool
     */
    public static function update_text(int $id, string $text): bool
    {
        global $wpdb;

        if ($id <= 0) {
            return false;
        }

        $text = sanitize_textarea_field($text);

        if ($text === '') {
            return false;
        }

        $table = AIJP_Database::table('proposals');

        $updated = $wpdb->update(
            $table,
            [
                'text' => $text,
                'source' => 'manual',
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        return $updated !== false;
    }

    /**
     * Mark a proposal as sent.
     *
     * Sending itself is always a manual, human action (ТЗ Етап 5:
     * "масове автонадсилання заборонене"). This method only records
     * that fact after the human has done it.
     *
     * @param int $id Proposal ID.
     * @return bool
     */
    public static function mark_sent(int $id): bool
    {
        global $wpdb;

        if ($id <= 0) {
            return false;
        }

        $table = AIJP_Database::table('proposals');

        $updated = $wpdb->update(
            $table,
            [
                'status' => self::STATUS_SENT,
                'sent_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        return $updated !== false;
    }

    /**
     * Record the client's reply to a sent proposal.
     *
     * @param int    $id    Proposal ID.
     * @param string $reply Client reply text.
     * @return bool
     */
    public static function save_client_reply(int $id, string $reply): bool
    {
        global $wpdb;

        if ($id <= 0) {
            return false;
        }

        $reply = sanitize_textarea_field($reply);

        $table = AIJP_Database::table('proposals');

        $updated = $wpdb->update(
            $table,
            [
                'client_reply' => $reply,
                'client_replied_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        return $updated !== false;
    }
}