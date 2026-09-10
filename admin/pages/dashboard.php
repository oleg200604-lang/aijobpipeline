<?php

if (!defined('ABSPATH')) {
    exit;
}

$source_count = AIJP_Source_Repository::count();
$job_count = AIJP_Job_Repository::count();
?>

<div class="wrap">

    <h1>AI Job Pipeline</h1>

    <div class="notice notice-info inline">
        <p>
            Sources: <strong><?php echo esc_html($source_count); ?></strong>
            &nbsp; | &nbsp;
            Imported jobs: <strong><?php echo esc_html($job_count); ?></strong>
        </p>
    </div>

    <p>
        <a
            class="button button-primary"
            href="<?php echo esc_url(admin_url('admin.php?page=aijp-jobs')); ?>"
        >
            View Jobs
        </a>

        <?php if (AIJP_Roles::can_manage_sources()) : ?>
            <a
                class="button"
                href="<?php echo esc_url(admin_url('admin.php?page=aijp-sources')); ?>"
            >
                Manage Sources
            </a>
        <?php endif; ?>
    </p>

</div>
