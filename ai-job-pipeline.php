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

$analysis = AIJP_Job_Repository::get_analysis(
    $job_id
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
| Proposals (відгуки, Етап 5 ТЗ)
|--------------------------------------------------------------------------
*/

$proposal_notice = '';
$proposal_notice_type = 'success';

if (
    current_user_can(AIJP_Roles::CAP_EDIT_JOBS) &&
    isset($_POST['aijp_proposal_action'])
) {
    check_admin_referer(
        'aijp_proposal_action_' . $job_id,
        'aijp_proposal_action_nonce'
    );

    $proposal_action = sanitize_key(
        wp_unslash($_POST['aijp_proposal_action'])
    );

    if ($proposal_action === 'generate') {
        $service = new AIJP_Proposal_Service();

        $lang_override = isset($_POST['lang'])
            ? sanitize_key(wp_unslash($_POST['lang']))
            : '';

        $generated = $service->generate($job_id, $lang_override);

        if (is_wp_error($generated)) {
            $proposal_notice = $generated->get_error_message();
            $proposal_notice_type = 'error';
        } else {
            $proposal_notice = __(
                'Proposal draft generated.',
                'ai-job-pipeline'
            );
        }
    }

    if ($proposal_action === 'save_edit') {
        $proposal_id = absint($_POST['proposal_id'] ?? 0);

        $text = sanitize_textarea_field(
            wp_unslash($_POST['proposal_text'] ?? '')
        );

        if (
            $proposal_id > 0 &&
            AIJP_Proposal_Repository::update_text($proposal_id, $text)
        ) {
            $proposal_notice = __(
                'Proposal draft saved.',
                'ai-job-pipeline'
            );
        } else {
            $proposal_notice = __(
                'Could not save the proposal draft.',
                'ai-job-pipeline'
            );
            $proposal_notice_type = 'error';
        }
    }

    if ($proposal_action === 'mark_sent') {
        $proposal_id = absint($_POST['proposal_id'] ?? 0);

        if (
            $proposal_id > 0 &&
            AIJP_Proposal_Repository::mark_sent($proposal_id)
        ) {
            AIJP_Job_Repository::update_status(
                $job_id,
                AIJP_Job_Status::PROPOSAL_SENT
            );

            $proposal_notice = __(
                'Proposal marked as sent. Job status updated to "proposal_sent".',
                'ai-job-pipeline'
            );
        } else {
            $proposal_notice = __(
                'Could not mark the proposal as sent.',
                'ai-job-pipeline'
            );
            $proposal_notice_type = 'error';
        }
    }
}

$proposals = AIJP_Proposal_Repository::get_all_for_job($job_id);
$latest_proposal = $proposals[0] ?? null;

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

<h2>
    <?php echo esc_html__('Proposal (відгук)', 'ai-job-pipeline'); ?>
</h2>

<?php if ($proposal_notice !== '') : ?>
    <div class="notice notice-<?php echo esc_attr($proposal_notice_type); ?>" style="clear: both;">
        <p><?php echo esc_html($proposal_notice); ?></p>
    </div>
<?php endif; ?>

<?php if (current_user_can(AIJP_Roles::CAP_EDIT_JOBS)) : ?>

    <form method="post" action="" style="margin-bottom: 12px;">
        <?php wp_nonce_field('aijp_proposal_action_' . $job_id, 'aijp_proposal_action_nonce'); ?>
        <input type="hidden" name="aijp_proposal_action" value="generate">
        <input
            type="text"
            name="lang"
            placeholder="<?php echo esc_attr__('Language code (optional, e.g. de)', 'ai-job-pipeline'); ?>"
            value="<?php echo esc_attr((string) ($job->lang ?? '')); ?>"
            style="width: 220px;"
        >
        <?php submit_button(
            __('Generate proposal draft (AI)', 'ai-job-pipeline'),
            'secondary',
            'submit',
            false
        ); ?>
    </form>

<?php endif; ?>

<?php if ($latest_proposal) : ?>

    <div class="card" style="max-width: 700px;">
        <p>
            <strong><?php echo esc_html(strtoupper($latest_proposal->status)); ?></strong>
            &middot; <?php echo esc_html((string) $latest_proposal->lang); ?>
            &middot; <?php echo esc_html((string) $latest_proposal->created_at); ?>
            <?php if ($latest_proposal->status === AIJP_Proposal_Repository::STATUS_SENT) : ?>
                &middot; <?php echo esc_html__('sent at', 'ai-job-pipeline'); ?> <?php echo esc_html((string) $latest_proposal->sent_at); ?>
            <?php endif; ?>
        </p>

        <?php if (
            $latest_proposal->status !== AIJP_Proposal_Repository::STATUS_SENT &&
            current_user_can(AIJP_Roles::CAP_EDIT_JOBS)
        ) : ?>

            <form method="post" action="">
                <?php wp_nonce_field('aijp_proposal_action_' . $job_id, 'aijp_proposal_action_nonce'); ?>
                <input type="hidden" name="aijp_proposal_action" value="save_edit">
                <input type="hidden" name="proposal_id" value="<?php echo esc_attr($latest_proposal->id); ?>">
                <textarea
                    name="proposal_text"
                    rows="8"
                    class="large-text"
                ><?php echo esc_textarea((string) $latest_proposal->text); ?></textarea>
                <p>
                    <?php submit_button(
                        __('Save edits', 'ai-job-pipeline'),
                        'secondary',
                        'submit',
                        false
                    ); ?>
                </p>
            </form>

            <form method="post" action="">
                <?php wp_nonce_field('aijp_proposal_action_' . $job_id, 'aijp_proposal_action_nonce'); ?>
                <input type="hidden" name="aijp_proposal_action" value="mark_sent">
                <input type="hidden" name="proposal_id" value="<?php echo esc_attr($latest_proposal->id); ?>">
                <?php submit_button(
                    __('Mark as sent', 'ai-job-pipeline'),
                    'primary',
                    'submit',
                    false
                ); ?>
                <em style="margin-left: 8px;">
                    <?php echo esc_html__(
                        'Sending itself stays manual — this only records that a human sent it.',
                        'ai-job-pipeline'
                    ); ?>
                </em>
            </form>

        <?php else : ?>

            <p style="white-space: pre-wrap;"><?php echo esc_html((string) $latest_proposal->text); ?></p>

        <?php endif; ?>
    </div>

<?php else : ?>

    <p><?php echo esc_html__('No proposal drafted yet for this job.', 'ai-job-pipeline'); ?></p>

<?php endif; ?>

<?php if (count($proposals) > 1) : ?>

    <details style="margin-top: 10px;">
        <summary><?php echo esc_html__('Previous drafts', 'ai-job-pipeline'); ?></summary>

        <?php foreach (array_slice($proposals, 1) as $old_proposal) : ?>
            <div style="border-top: 1px solid #ccd0d4; padding: 8px 0;">
                <p>
                    <strong><?php echo esc_html(strtoupper($old_proposal->status)); ?></strong>
                    &middot; <?php echo esc_html((string) $old_proposal->created_at); ?>
                </p>
                <p style="white-space: pre-wrap;"><?php echo esc_html((string) $old_proposal->text); ?></p>
            </div>
        <?php endforeach; ?>
    </details>

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