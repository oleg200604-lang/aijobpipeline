<?php

if (!defined('ABSPATH')) {
    exit;
}

if (
    !current_user_can(
        AIJP_Roles::CAP_MANAGE_SETTINGS
    )
) {
    wp_die(
        esc_html__(
            'You do not have permission to access this page.',
            'ai-job-pipeline'
        )
    );
}

/*
|--------------------------------------------------------------------------
| Handle manual pipeline запуск
|--------------------------------------------------------------------------
*/

$run_result = null;

if (
    isset($_POST['aijp_run_ai_pipeline'])
) {
    check_admin_referer(
        'aijp_run_ai_pipeline_action',
        'aijp_run_ai_pipeline_nonce'
    );

    $batch_size = isset(
        $_POST['batch_size']
    )
        ? absint(
            wp_unslash(
                $_POST['batch_size']
            )
        )
        : 5;

    $batch_size = max(
        1,
        min(
            $batch_size,
            50
        )
    );

    $run_result = aijp_run_ai_pipeline(
        $batch_size
    );
}

/*
|--------------------------------------------------------------------------
| Queue statistics
|--------------------------------------------------------------------------
*/

global $wpdb;

$jobs_table = AIJP_Database::table(
    'jobs'
);

$statuses = [
    'found' => 0,
    'analyzed' => 0,
    'skipped' => 0,
    'rejected' => 0,
    'analysis_failed' => 0,
];

$rows = $wpdb->get_results(
    "
    SELECT
        status,
        COUNT(*) AS total
    FROM {$jobs_table}
    GROUP BY status
    "
);

if (is_array($rows)) {
    foreach ($rows as $row) {
        if (
            isset($statuses[$row->status])
        ) {
            $statuses[$row->status] =
                absint(
                    $row->total
                );
        }
    }
}

$found_status =
    defined('AIJP_Job_Status::FOUND')
        ? AIJP_Job_Status::FOUND
        : 'found';

$analyzed_status =
    defined('AIJP_Job_Status::ANALYZED')
        ? AIJP_Job_Status::ANALYZED
        : 'analyzed';

$rejected_status =
    defined('AIJP_Job_Status::REJECTED')
        ? AIJP_Job_Status::REJECTED
        : 'rejected';

$skipped_status =
    defined('AIJP_Job_Status::SKIPPED')
        ? AIJP_Job_Status::SKIPPED
        : 'skipped';

$failed_status =
    defined(
        'AIJP_Job_Status::ANALYSIS_FAILED'
    )
        ? AIJP_Job_Status::ANALYSIS_FAILED
        : 'analysis_failed';

/*
|--------------------------------------------------------------------------
| Load queue jobs
|--------------------------------------------------------------------------
*/

$queue_limit = 20;

$queue_jobs = $wpdb->get_results(
    $wpdb->prepare(
        "
        SELECT *
        FROM {$jobs_table}
        WHERE status = %s
        ORDER BY id ASC
        LIMIT %d
        ",
        $found_status,
        $queue_limit
    )
);

?>

<div class="wrap">

<h1>
    <?php
    echo esc_html__(
        'AI Queue',
        'ai-job-pipeline'
    );
    ?>
</h1>

<p>
    <?php
    echo esc_html__(
        'Jobs waiting for AI analysis are processed in controlled batches.',
        'ai-job-pipeline'
    );
    ?>
</p>

<?php if ($run_result !== null) : ?>

    <?php if (
        !empty($run_result['locked'])
    ) : ?>

        <div class="notice notice-warning">
            <p>
                <?php
                echo esc_html__(
                    'The AI pipeline is already running. Please wait for the current batch to finish.',
                    'ai-job-pipeline'
                );
                ?>
            </p>
        </div>

    <?php elseif (
        !empty($run_result['success'])
    ) : ?>

        <div class="notice notice-success">
            <p>
                <?php
                printf(
                    esc_html__(
                        'AI pipeline completed. Found: %1$d, processed: %2$d, analyzed: %3$d, skipped: %4$d, failed: %5$d.',
                        'ai-job-pipeline'
                    ),
                    absint(
                        $run_result['found']
                        ?? 0
                    ),
                    absint(
                        $run_result['processed']
                        ?? 0
                    ),
                    absint(
                        $run_result['analyzed']
                        ?? 0
                    ),
                    absint(
                        $run_result['skipped']
                        ?? 0
                    ),
                    absint(
                        $run_result['failed']
                        ?? 0
                    )
                );
                ?>
            </p>
        </div>

    <?php else : ?>

        <div class="notice notice-error">
            <p>
                <?php
                echo esc_html__(
                    'AI pipeline finished with errors. Check the plugin logs for details.',
                    'ai-job-pipeline'
                );
                ?>
            </p>
        </div>

    <?php endif; ?>

<?php endif; ?>


<hr>


<h2>
    <?php
    echo esc_html__(
        'Pipeline Status',
        'ai-job-pipeline'
    );
    ?>
</h2>


<table
    class="widefat striped"
    style="max-width: 900px;"
>

    <thead>
        <tr>

            <th>
                <?php
                echo esc_html__(
                    'Status',
                    'ai-job-pipeline'
                );
                ?>
            </th>

            <th>
                <?php
                echo esc_html__(
                    'Jobs',
                    'ai-job-pipeline'
                );
                ?>
            </th>

        </tr>
    </thead>

    <tbody>

        <tr>

            <td>
                <?php
                echo esc_html(
                    strtoupper(
                        $found_status
                    )
                );
                ?>
            </td>

            <td>
                <?php
                echo esc_html(
                    $statuses[
                        $found_status
                    ]
                    ?? 0
                );
                ?>
            </td>

        </tr>

        <tr>

            <td>
                <?php
                echo esc_html(
                    strtoupper(
                        $skipped_status
                    )
                );
                ?>
            </td>

            <td>
                <?php
                echo esc_html(
                    $statuses[
                        $skipped_status
                    ]
                    ?? 0
                );
                ?>
            </td>

        </tr>

        <tr>

            <td>
                <?php
                echo esc_html(
                    strtoupper(
                        $analyzed_status
                    )
                );
                ?>
            </td>

            <td>
                <?php
                echo esc_html(
                    $statuses[
                        $analyzed_status
                    ]
                    ?? 0
                );
                ?>
            </td>

        </tr>

        <tr>

            <td>
                <?php
                echo esc_html(
                    strtoupper(
                        $rejected_status
                    )
                );
                ?>
            </td>

            <td>
                <?php
                echo esc_html(
                    $statuses[
                        $rejected_status
                    ]
                    ?? 0
                );
                ?>
            </td>

        </tr>

        <tr>

            <td>
                <?php
                echo esc_html(
                    strtoupper(
                        $failed_status
                    )
                );
                ?>
            </td>

            <td>
                <?php
                echo esc_html(
                    $statuses[
                        $failed_status
                    ]
                    ?? 0
                );
                ?>
            </td>

        </tr>

    </tbody>

</table>


<hr>


<h2>
    <?php
    echo esc_html__(
        'Run AI Pipeline',
        'ai-job-pipeline'
    );
    ?>
</h2>


<?php if (
    ($statuses[$found_status] ?? 0) > 0
) : ?>

    <form
        method="post"
        action=""
    >

        <?php
        wp_nonce_field(
            'aijp_run_ai_pipeline_action',
            'aijp_run_ai_pipeline_nonce'
        );
        ?>

        <input
            type="hidden"
            name="aijp_run_ai_pipeline"
            value="1"
        >

        <table class="form-table">

            <tr>

                <th scope="row">

                    <label for="batch_size">

                        <?php
                        echo esc_html__(
                            'Batch size',
                            'ai-job-pipeline'
                        );
                        ?>

                    </label>

                </th>

                <td>

                    <input
                        type="number"
                        id="batch_size"
                        name="batch_size"
                        value="5"
                        min="1"
                        max="50"
                        class="small-text"
                    >

                    <p class="description">

                        <?php
                        echo esc_html__(
                            'Number of jobs to send to the AI pipeline during this run.',
                            'ai-job-pipeline'
                        );
                        ?>

                    </p>

                </td>

            </tr>

        </table>


        <?php
        submit_button(
            __(
                'Run AI Pipeline',
                'ai-job-pipeline'
            ),
            'primary',
            'submit',
            false
        );
        ?>

    </form>

<?php else : ?>

    <p>

        <?php
        echo esc_html__(
            'There are currently no jobs waiting for AI analysis.',
            'ai-job-pipeline'
        );
        ?>

    </p>

<?php endif; ?>


<hr>


<h2>
    <?php
    echo esc_html__(
        'Next Jobs in Queue',
        'ai-job-pipeline'
    );
    ?>
</h2>


<?php if (
    !empty($queue_jobs)
) : ?>

    <table class="widefat striped">

        <thead>

            <tr>

                <th>
                    ID
                </th>

                <th>
                    <?php
                    echo esc_html__(
                        'Title',
                        'ai-job-pipeline'
                    );
                    ?>
                </th>

                <th>
                    <?php
                    echo esc_html__(
                        'Source',
                        'ai-job-pipeline'
                    );
                    ?>
                </th>

                <th>
                    <?php
                    echo esc_html__(
                        'Created',
                        'ai-job-pipeline'
                    );
                    ?>
                </th>

            </tr>

        </thead>

        <tbody>

            <?php foreach (
                $queue_jobs as $job
            ) : ?>

                <tr>

                    <td>

                        <?php
                        echo esc_html(
                            absint(
                                $job->id
                            )
                        );
                        ?>

                    </td>

                    <td>

                        <?php
                        echo esc_html(
                            $job->title
                            ?? ''
                        );
                        ?>

                    </td>

                    <td>

                        <?php
                        echo esc_html(
                            $job->source_name
                            ?? $job->source_id
                            ?? ''
                        );
                        ?>

                    </td>

                    <td>

                        <?php
                        echo esc_html(
                            $job->created_at
                            ?? ''
                        );
                        ?>

                    </td>

                </tr>

            <?php endforeach; ?>

        </tbody>

    </table>

<?php else : ?>

    <p>

        <?php
        echo esc_html__(
            'The AI queue is empty.',
            'ai-job-pipeline'
        );
        ?>

    </p>

<?php endif; ?>

</div>
