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
| Get job ID
|--------------------------------------------------------------------------
*/

$job_id = isset($_GET['job_id'])
    ? absint(
        wp_unslash(
            $_GET['job_id']
        )
    )
    : 0;

if ($job_id <= 0) {
    wp_die(
        esc_html__(
            'Invalid job ID.',
            'ai-job-pipeline'
        )
    );
}

/*
|--------------------------------------------------------------------------
| Load job
|--------------------------------------------------------------------------
*/

$job = AIJP_Job_Repository::get(
    $job_id
);

if (!$job) {
    wp_die(
        esc_html__(
            'Job not found.',
            'ai-job-pipeline'
        )
    );
}

/*
|--------------------------------------------------------------------------
| Load AI analysis
|--------------------------------------------------------------------------
*/

global $wpdb;

$analyses_table = AIJP_Database::table(
    'analyses'
);

$analysis = $wpdb->get_row(
    $wpdb->prepare(
        "
        SELECT *
        FROM {$analyses_table}
        WHERE job_id = %d
        ORDER BY id DESC
        LIMIT 1
        ",
        $job_id
    )
);

/*
|--------------------------------------------------------------------------
| Decode JSON fields
|--------------------------------------------------------------------------
*/

$plan = [];

$red_flags = [];

if (
    $analysis &&
    !empty($analysis->plan)
) {
    $decoded_plan = json_decode(
        $analysis->plan,
        true
    );

    if (is_array($decoded_plan)) {
        $plan = $decoded_plan;
    }
}

if (
    $analysis &&
    !empty($analysis->red_flags)
) {
    $decoded_red_flags = json_decode(
        $analysis->red_flags,
        true
    );

    if (is_array($decoded_red_flags)) {
        $red_flags = $decoded_red_flags;
    }
}

/*
|--------------------------------------------------------------------------
| Status helpers
|--------------------------------------------------------------------------
*/

$status = isset($job->status)
    ? (string) $job->status
    : '';

$status_class = 'notice-info';

switch ($status) {
    case 'analyzed':
        $status_class = 'notice-success';
        break;

    case 'rejected':
        $status_class = 'notice-warning';
        break;

    case 'analysis_failed':
        $status_class = 'notice-error';
        break;

    case 'found':
    default:
        $status_class = 'notice-info';
        break;
}

?>

<div class="wrap">

<h1>
    <?php
    echo esc_html__(
        'Job Details',
        'ai-job-pipeline'
    );
    ?>
</h1>


<p>

    <a
        href="<?php echo esc_url(
            admin_url(
                'admin.php?page=aijp-jobs'
            )
        ); ?>"
        class="button"
    >

        <?php
        echo esc_html__(
            '← Back to Jobs',
            'ai-job-pipeline'
        );
        ?>

    </a>

</p>


<div
    class="notice <?php echo esc_attr(
        $status_class
    ); ?>"
>

    <p>

        <strong>

            <?php
            echo esc_html__(
                'Status:',
                'ai-job-pipeline'
            );
            ?>

        </strong>

        <?php
        echo esc_html(
            strtoupper($status)
        );
        ?>

    </p>

</div>


<hr>


<h2>

    <?php
    echo esc_html__(
        'Job Information',
        'ai-job-pipeline'
    );
    ?>

</h2>


<table
    class="widefat striped"
    style="max-width: 1100px;"
>

    <tbody>

        <tr>

            <th
                style="width: 200px;"
            >

                <?php
                echo esc_html__(
                    'ID',
                    'ai-job-pipeline'
                );
                ?>

            </th>

            <td>

                <?php
                echo esc_html(
                    absint(
                        $job->id
                    )
                );
                ?>

            </td>

        </tr>

        <tr>

            <th>

                <?php
                echo esc_html__(
                    'Title',
                    'ai-job-pipeline'
                );
                ?>

            </th>

            <td>

                <?php
                echo esc_html(
                    $job->title
                    ?? ''
                );
                ?>

            </td>

        </tr>


        <?php if (
            !empty($job->url)
        ) : ?>

            <tr>

                <th>

                    <?php
                    echo esc_html__(
                        'Source URL',
                        'ai-job-pipeline'
                    );
                    ?>

                </th>

                <td>

                    <a
                        href="<?php echo esc_url(
                            $job->url
                        ); ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                    >

                        <?php
                        echo esc_html__(
                            'Open original job',
                            'ai-job-pipeline'
                        );
                        ?>

                    </a>

                </td>

            </tr>

        <?php endif; ?>


        <?php if (
            !empty($job->company)
        ) : ?>

            <tr>

                <th>

                    <?php
                    echo esc_html__(
                        'Company',
                        'ai-job-pipeline'
                    );
                    ?>

                </th>

                <td>

                    <?php
                    echo esc_html(
                        $job->company
                    );
                    ?>

                </td>

            </tr>

        <?php endif; ?>


        <?php if (
            !empty($job->location)
        ) : ?>

            <tr>

                <th>

                    <?php
                    echo esc_html__(
                        'Location',
                        'ai-job-pipeline'
                    );
                    ?>

                </th>

                <td>

                    <?php
                    echo esc_html(
                        $job->location
                    );
                    ?>

                </td>

            </tr>

        <?php endif; ?>


        <?php if (
            !empty($job->created_at)
        ) : ?>

            <tr>

                <th>

                    <?php
                    echo esc_html__(
                        'Imported',
                        'ai-job-pipeline'
                    );
                    ?>

                </th>

                <td>

                    <?php
                    echo esc_html(
                        $job->created_at
                    );
                    ?>

                </td>

            </tr>

        <?php endif; ?>

    </tbody>

</table>


<?php if (
    !empty($job->description)
) : ?>

    <hr>


    <h2>

        <?php
        echo esc_html__(
            'Job Description',
            'ai-job-pipeline'
        );
        ?>

    </h2>


    <div
        style="
            max-width: 1100px;
            background: #fff;
            border: 1px solid #ccd0d4;
            padding: 20px;
            box-sizing: border-box;
        "
    >

        <?php
        echo wp_kses_post(
            wpautop(
                make_clickable(
                    esc_html(
                        $job->description
                    )
                )
            )
        );
        ?>

    </div>

<?php endif; ?>


<hr>


<h2>

    <?php
    echo esc_html__(
        'AI Analysis',
        'ai-job-pipeline'
    );
    ?>

</h2>


<?php if (!$analysis) : ?>

    <div class="notice notice-info">

        <p>

            <?php
            echo esc_html__(
                'This job has not been analyzed by AI yet.',
                'ai-job-pipeline'
            );
            ?>

        </p>

    </div>

<?php else : ?>


    <table
        class="widefat striped"
        style="max-width: 1100px;"
    >

        <tbody>


            <?php if (
                !empty($analysis->summary)
            ) : ?>

                <tr>

                    <th
                        style="width: 200px;"
                    >

                        <?php
                        echo esc_html__(
                            'Summary',
                            'ai-job-pipeline'
                        );
                        ?>

                    </th>

                    <td>

                        <?php
                        echo wp_kses_post(
                            wpautop(
                                esc_html(
                                    $analysis->summary
                                )
                            )
                        );
                        ?>

                    </td>

                </tr>

            <?php endif; ?>


            <?php if (
                !empty($analysis->category)
            ) : ?>

                <tr>

                    <th>

                        <?php
                        echo esc_html__(
                            'Category',
                            'ai-job-pipeline'
                        );
                        ?>

                    </th>

                    <td>

                        <?php
                        echo esc_html(
                            $analysis->category
                        );
                        ?>

                    </td>

                </tr>

            <?php endif; ?>


            <?php if (
                !empty($analysis->complexity)
            ) : ?>

                <tr>

                    <th>

                        <?php
                        echo esc_html__(
                            'Complexity',
                            'ai-job-pipeline'
                        );
                        ?>

                    </th>

                    <td>

                        <?php
                        echo esc_html(
                            $analysis->complexity
                        );
                        ?>

                    </td>

                </tr>

            <?php endif; ?>


            <tr>

                <th>

                    <?php
                    echo esc_html__(
                        'AI Feasible',
                        'ai-job-pipeline'
                    );
                    ?>

                </th>

                <td>

                    <?php
                    echo absint(
                        $analysis->ai_feasible
                    ) === 1
                        ? esc_html__(
                            'Yes',
                            'ai-job-pipeline'
                        )
                        : esc_html__(
                            'No',
                            'ai-job-pipeline'
                        );
                    ?>

                </td>

            </tr>


            <?php if (
                !empty(
                    $analysis->ai_feasible_reason
                )
            ) : ?>

                <tr>

                    <th>

                        <?php
                        echo esc_html__(
                            'Feasibility Reason',
                            'ai-job-pipeline'
                        );
                        ?>

                    </th>

                    <td>

                        <?php
                        echo esc_html(
                            $analysis->ai_feasible_reason
                        );
                        ?>

                    </td>

                </tr>

            <?php endif; ?>


            <?php if (
                $analysis->estimated_hours !== null
            ) : ?>

                <tr>

                    <th>

                        <?php
                        echo esc_html__(
                            'Estimated Hours',
                            'ai-job-pipeline'
                        );
                        ?>

                    </th>

                    <td>

                        <?php
                        echo esc_html(
                            $analysis->estimated_hours
                        );
                        ?>

                    </td>

                </tr>

            <?php endif; ?>


            <?php if (
                !empty(
                    $analysis->recommendation
                )
            ) : ?>

                <tr>

                    <th>

                        <?php
                        echo esc_html__(
                            'Recommendation',
                            'ai-job-pipeline'
                        );
                        ?>

                    </th>

                    <td>

                        <strong>

                            <?php
                            echo esc_html(
                                strtoupper(
                                    $analysis->recommendation
                                )
                            );
                            ?>

                        </strong>

                    </td>

                </tr>

            <?php endif; ?>


            <?php if (
                $analysis->confidence !== null
            ) : ?>

                <tr>

                    <th>

                        <?php
                        echo esc_html__(
                            'Confidence',
                            'ai-job-pipeline'
                        );
                        ?>

                    </th>

                    <td>

                        <?php
                        echo esc_html(
                            number_format(
                                (float)
                                    $analysis->confidence,
                                2
                            )
                        );
                        ?>

                    </td>

                </tr>

            <?php endif; ?>


            <?php if (
                !empty(
                    $analysis->model
                )
            ) : ?>

                <tr>

                    <th>

                        <?php
                        echo esc_html__(
                            'AI Model',
                            'ai-job-pipeline'
                        );
                        ?>

                    </th>

                    <td>

                        <?php
                        echo esc_html(
                            $analysis->model
                        );
                        ?>

                    </td>

                </tr>

            <?php endif; ?>


            <?php if (
                !empty(
                    $analysis->created_at
                )
            ) : ?>

                <tr>

                    <th>

                        <?php
                        echo esc_html__(
                            'Analyzed At',
                            'ai-job-pipeline'
                        );
                        ?>

                    </th>

                    <td>

                        <?php
                        echo esc_html(
                            $analysis->created_at
                        );
                        ?>

                    </td>

                </tr>

            <?php endif; ?>

        </tbody>

    </table>


    <?php if (!empty($plan)) : ?>

        <hr>


        <h2>

            <?php
            echo esc_html__(
                'Execution Plan',
                'ai-job-pipeline'
            );
            ?>

        </h2>


        <ol
            style="
                max-width: 1100px;
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px 20px 20px 40px;
            "
        >

            <?php foreach (
                $plan as $step
            ) : ?>

                <li>

                    <?php
                    if (is_array($step)) {
                        echo esc_html(
                            wp_json_encode(
                                $step,
                                JSON_UNESCAPED_UNICODE
                            )
                        );
                    } else {
                        echo esc_html(
                            (string) $step
                        );
                    }
                    ?>

                </li>

            <?php endforeach; ?>

        </ol>

    <?php endif; ?>


    <?php if (!empty($red_flags)) : ?>

        <hr>


        <h2>

            <?php
            echo esc_html__(
                'Red Flags',
                'ai-job-pipeline'
            );
            ?>

        </h2>


        <ul
            style="
                max-width: 1100px;
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px 20px 20px 40px;
            "
        >

            <?php foreach (
                $red_flags as $red_flag
            ) : ?>

                <li>

                    <?php
                    if (is_array($red_flag)) {
                        echo esc_html(
                            wp_json_encode(
                                $red_flag,
                                JSON_UNESCAPED_UNICODE
                            )
                        );
                    } else {
                        echo esc_html(
                            (string) $red_flag
                        );
                    }
                    ?>

                </li>

            <?php endforeach; ?>

        </ul>

    <?php endif; ?>


<?php endif; ?>


<hr>


<p>

    <a
        href="<?php echo esc_url(
            admin_url(
                'admin.php?page=aijp-jobs'
            )
        ); ?>"
        class="button"
    >

        <?php
        echo esc_html__(
            '← Back to Jobs',
            'ai-job-pipeline'
        );
        ?>

    </a>

</p>

</div>
