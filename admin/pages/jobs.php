<?php

if (!defined('ABSPATH')) {
    exit;
}

if (
    !current_user_can(
        AIJP_Roles::CAP_VIEW_JOBS
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
| Pagination
|--------------------------------------------------------------------------
*/

$current_page = isset($_GET['paged'])
    ? max(
        1,
        absint(
            wp_unslash(
                $_GET['paged']
            )
        )
    )
    : 1;

$per_page = 20;

$offset =
    ($current_page - 1)
    * $per_page;

/*
|--------------------------------------------------------------------------
| Optional status filter
|--------------------------------------------------------------------------
*/

$status_filter = isset($_GET['status'])
    ? sanitize_key(
        wp_unslash(
            $_GET['status']
        )
    )
    : '';

$allowed_statuses = [
    'found',
    'analyzed',
    'skipped',
    'rejected',
    'analysis_failed',
];

if (
    !in_array(
        $status_filter,
        $allowed_statuses,
        true
    )
) {
    $status_filter = '';
}

/*
|--------------------------------------------------------------------------
| Database tables
|--------------------------------------------------------------------------
*/

global $wpdb;

$jobs_table = AIJP_Database::table(
    'jobs'
);

$analyses_table = AIJP_Database::table(
    'analyses'
);

/*
|--------------------------------------------------------------------------
| Build WHERE clause
|--------------------------------------------------------------------------
*/

$where = '';

$query_args = [];

if ($status_filter !== '') {
    $where = 'WHERE j.status = %s';

    $query_args[] = $status_filter;
}

/*
|--------------------------------------------------------------------------
| Count jobs
|--------------------------------------------------------------------------
*/

$count_sql = "
    SELECT COUNT(*)
    FROM {$jobs_table} AS j
    {$where}
";

if (!empty($query_args)) {
    $count_sql = $wpdb->prepare(
        $count_sql,
        ...$query_args
    );
}

$total_items = (int)
    $wpdb->get_var(
        $count_sql
    );

$total_pages = max(
    1,
    (int) ceil(
        $total_items
        / $per_page
    )
);

/*
|--------------------------------------------------------------------------
| Load jobs with latest AI analysis
|--------------------------------------------------------------------------
*/

$jobs_sql = "
    SELECT
        j.*,

        a.id AS analysis_id,
        a.recommendation AS ai_recommendation,
        a.confidence AS ai_confidence,
        a.ai_feasible AS ai_feasible,
        a.complexity AS ai_complexity

    FROM {$jobs_table} AS j

    LEFT JOIN {$analyses_table} AS a
        ON a.id = (
            SELECT a2.id
            FROM {$analyses_table} AS a2
            WHERE a2.job_id = j.id
            ORDER BY a2.id DESC
            LIMIT 1
        )

    {$where}

    ORDER BY j.id DESC

    LIMIT %d
    OFFSET %d
";

$jobs_query_args = $query_args;

$jobs_query_args[] = $per_page;
$jobs_query_args[] = $offset;

$jobs_sql = $wpdb->prepare(
    $jobs_sql,
    ...$jobs_query_args
);

$jobs = $wpdb->get_results(
    $jobs_sql
);

/*
|--------------------------------------------------------------------------
| Status counts
|--------------------------------------------------------------------------
*/

$status_counts = [
    'found' => 0,
    'analyzed' => 0,
    'skipped' => 0,
    'rejected' => 0,
    'analysis_failed' => 0,
];

$count_rows = $wpdb->get_results(
    "
    SELECT
        status,
        COUNT(*) AS total
    FROM {$jobs_table}
    GROUP BY status
    "
);

if (is_array($count_rows)) {
    foreach ($count_rows as $row) {
        if (
            array_key_exists(
                $row->status,
                $status_counts
            )
        ) {
            $status_counts[
                $row->status
            ] = absint(
                $row->total
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Get status CSS class.
 *
 * @param string $status Job status.
 *
 * @return string
 */
function aijp_admin_job_status_class(
    string $status
): string {
    switch ($status) {
        case 'analyzed':
            return 'aijp-status-analyzed';

        case 'rejected':
        case 'skipped':
            return 'aijp-status-rejected';

        case 'analysis_failed':
            return 'aijp-status-failed';

        case 'found':
        default:
            return 'aijp-status-found';
    }
}

/**
 * Build jobs page URL.
 *
 * @param array<string,mixed> $args URL arguments.
 *
 * @return string
 */
function aijp_admin_jobs_url(
    array $args = []
): string {
    $base_args = [
        'page' => 'aijp-jobs',
    ];

    return add_query_arg(
        array_merge(
            $base_args,
            $args
        ),
        admin_url(
            'admin.php'
        )
    );
}

?>

<div class="wrap">

<h1 class="wp-heading-inline">

    <?php
    echo esc_html__(
        'Jobs',
        'ai-job-pipeline'
    );
    ?>

</h1>


<a
    href="<?php echo esc_url(
        admin_url(
            'admin.php?page=aijp-ai-queue'
        )
    ); ?>"
    class="page-title-action"
>

    <?php
    echo esc_html__(
        'AI Queue',
        'ai-job-pipeline'
    );
    ?>

</a>


<hr class="wp-header-end">


<style>

    .aijp-status {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
        line-height: 1;
        text-transform: uppercase;
    }

    .aijp-status-found {
        background: #e5e5e5;
    }

    .aijp-status-analyzed {
        background: #d7f5df;
    }

    .aijp-status-rejected {
        background: #fff0c2;
    }

    .aijp-status-failed {
        background: #f8d7da;
    }

    .aijp-recommendation {
        font-weight: 600;
        text-transform: uppercase;
    }

</style>


<ul class="subsubsub">

    <li>

        <a
            href="<?php echo esc_url(
                aijp_admin_jobs_url()
            ); ?>"
            class="<?php
            echo $status_filter === ''
                ? 'current'
                : '';
            ?>"
        >

            <?php
            printf(
                esc_html__(
                    'All (%d)',
                    'ai-job-pipeline'
                ),
                array_sum(
                    $status_counts
                )
            );
            ?>

        </a>

        |

    </li>


    <?php foreach (
        $status_counts as $status => $count
    ) : ?>

        <li>

            <a
                href="<?php echo esc_url(
                    aijp_admin_jobs_url(
                        [
                            'status' => $status,
                        ]
                    )
                ); ?>"
                class="<?php
                echo $status_filter === $status
                    ? 'current'
                    : '';
                ?>"
            >

                <?php
                echo esc_html(
                    ucfirst(
                        str_replace(
                            '_',
                            ' ',
                            $status
                        )
                    )
                );
                ?>

                (<?php
                echo esc_html(
                    $count
                );
                ?>)

            </a>

            <?php if (
                $status !== 'analysis_failed'
            ) : ?>

                |

            <?php endif; ?>

        </li>

    <?php endforeach; ?>

</ul>


<br class="clear">


<table
    class="wp-list-table widefat fixed striped table-view-list"
>

    <thead>

        <tr>

            <th
                scope="col"
                style="width: 70px;"
            >

                <?php
                echo esc_html__(
                    'ID',
                    'ai-job-pipeline'
                );
                ?>

            </th>

            <th
                scope="col"
            >

                <?php
                echo esc_html__(
                    'Job',
                    'ai-job-pipeline'
                );
                ?>

            </th>

            <th
                scope="col"
                style="width: 120px;"
            >

                <?php
                echo esc_html__(
                    'Status',
                    'ai-job-pipeline'
                );
                ?>

            </th>

            <th
                scope="col"
                style="width: 140px;"
            >

                <?php
                echo esc_html__(
                    'AI Recommendation',
                    'ai-job-pipeline'
                );
                ?>

            </th>

            <th
                scope="col"
                style="width: 110px;"
            >

                <?php
                echo esc_html__(
                    'Confidence',
                    'ai-job-pipeline'
                );
                ?>

            </th>

            <th
                scope="col"
                style="width: 130px;"
            >

                <?php
                echo esc_html__(
                    'Complexity',
                    'ai-job-pipeline'
                );
                ?>

            </th>

            <th
                scope="col"
                style="width: 170px;"
            >

                <?php
                echo esc_html__(
                    'Imported',
                    'ai-job-pipeline'
                );
                ?>

            </th>

        </tr>

    </thead>


    <tbody>

        <?php if (
            empty($jobs)
        ) : ?>

            <tr>

                <td
                    colspan="7"
                >

                    <?php
                    echo esc_html__(
                        'No jobs found.',
                        'ai-job-pipeline'
                    );
                    ?>

                </td>

            </tr>

        <?php else : ?>

            <?php foreach (
                $jobs as $job
            ) : ?>

                <?php
                $job_id = absint(
                    $job->id
                );

                $job_url = add_query_arg(
                    [
                        'page'   => 'aijp-job',
                        'job_id' => $job_id,
                    ],
                    admin_url(
                        'admin.php'
                    )
                );

                $status = (string)
                    ($job->status ?? '');

                $status_class =
                    aijp_admin_job_status_class(
                        $status
                    );
                ?>

                <tr>

                    <td>

                        <?php
                        echo esc_html(
                            $job_id
                        );
                        ?>

                    </td>


                    <td>

                        <strong>

                            <a
                                href="<?php echo esc_url(
                                    $job_url
                                ); ?>"
                            >

                                <?php
                                echo esc_html(
                                    $job->title
                                    ?? ''
                                );
                                ?>

                            </a>

                        </strong>


                        <?php if (
                            !empty(
                                $job->company
                            )
                        ) : ?>

                            <div
                                class="row-actions"
                            >

                                <?php
                                echo esc_html(
                                    $job->company
                                );
                                ?>

                            </div>

                        <?php endif; ?>


                        <?php if (
                            !empty(
                                $job->location
                            )
                        ) : ?>

                            <div
                                style="
                                    margin-top: 5px;
                                    color: #646970;
                                "
                            >

                                <?php
                                echo esc_html(
                                    $job->location
                                );
                                ?>

                            </div>

                        <?php endif; ?>


                        <?php if (
                            !empty(
                                $job->url
                            )
                        ) : ?>

                            <div
                                style="
                                    margin-top: 5px;
                                "
                            >

                                <a
                                    href="<?php echo esc_url(
                                        $job->url
                                    ); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >

                                    <?php
                                    echo esc_html__(
                                        'Original job',
                                        'ai-job-pipeline'
                                    );
                                    ?>

                                </a>

                            </div>

                        <?php endif; ?>

                    </td>


                    <td>

                        <span
                            class="
                                aijp-status
                                <?php echo esc_attr(
                                    $status_class
                                ); ?>
                            "
                        >

                            <?php
                            echo esc_html(
                                str_replace(
                                    '_',
                                    ' ',
                                    $status
                                )
                            );
                            ?>

                        </span>

                    </td>


                    <td>

                        <?php if (
                            !empty(
                                $job->ai_recommendation
                            )
                        ) : ?>

                            <span
                                class="aijp-recommendation"
                            >

                                <?php
                                echo esc_html(
                                    $job->ai_recommendation
                                );
                                ?>

                            </span>

                        <?php else : ?>

                            —

                        <?php endif; ?>

                    </td>


                    <td>

                        <?php if (
                            $job->ai_confidence !== null
                        ) : ?>

                            <?php
                            echo esc_html(
                                number_format(
                                    (float)
                                        $job->ai_confidence,
                                    2
                                )
                            );
                            ?>

                        <?php else : ?>

                            —

                        <?php endif; ?>

                    </td>


                    <td>

                        <?php
                        if (
                            !empty(
                                $job->ai_complexity
                            )
                        ) {
                            echo esc_html(
                                $job->ai_complexity
                            );
                        } else {
                            echo '—';
                        }
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

        <?php endif; ?>

    </tbody>

</table>


<?php if (
    $total_pages > 1
) : ?>

    <div
        class="tablenav"
    >

        <div
            class="tablenav-pages"
        >

            <?php
            echo wp_kses_post(
                paginate_links(
                    [
                        'base' => add_query_arg(
                            'paged',
                            '%#%',
                            aijp_admin_jobs_url(
                                $status_filter !== ''
                                    ? [
                                        'status' => $status_filter,
                                    ]
                                    : []
                            )
                        ),

                        'format' => '',

                        'current' => $current_page,

                        'total' => $total_pages,

                        'prev_text' => '&laquo;',

                        'next_text' => '&raquo;',
                    ]
                )
            );
            ?>

        </div>

    </div>

<?php endif; ?>

</div>
