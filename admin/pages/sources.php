<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sources management page.
 *
 * Access:
 * - aij_manage_sources is required to view and manage sources.
 *
 * Security:
 * - capability is checked before rendering;
 * - capability is checked again for every POST operation;
 * - every destructive/write operation requires its own nonce;
 * - all displayed values are escaped.
 */

if (!current_user_can(AIJP_Roles::CAP_MANAGE_SOURCES)) {
    wp_die(
        esc_html__(
            'You do not have permission to manage sources.',
            'ai-job-pipeline'
        )
    );
}

/**
 * Run an import manually.
 */
if (
    'POST' === $_SERVER['REQUEST_METHOD'] &&
    isset($_POST['aijp_run_import'])
) {
    check_admin_referer('aijp_run_import');

    $result = (new AIJP_Job_Importer())
        ->import_enabled_sources();

    wp_safe_redirect(
        add_query_arg(
            [
                'page' => 'aijp-sources',
                'status' => !empty($result['errors'])
                    ? 'import_partial'
                    : 'import_complete',
                'found' => absint($result['found'] ?? 0),
                'added' => absint($result['added'] ?? 0),
                'duplicates' => absint(
                    $result['duplicates'] ?? 0
                ),
                'failed' => absint(
                    ($result['failed'] ?? 0) +
                    ($result['errors'] ?? 0)
                ),
            ],
            admin_url('admin.php')
        )
    );

    exit;
}

/**
 * Handle source deletion.
 *
 * Deletion is intentionally handled here instead of relying on a GET link.
 * This prevents accidental deletion through crawlers, prefetchers or
 * externally supplied URLs.
 */
if (
    'POST' === $_SERVER['REQUEST_METHOD'] &&
    isset($_POST['aijp_delete_source'])
) {
    if (!current_user_can(AIJP_Roles::CAP_MANAGE_SOURCES)) {
        wp_die(
            esc_html__(
                'You do not have permission to delete sources.',
                'ai-job-pipeline'
            )
        );
    }

    $source_id = isset($_POST['source_id'])
        ? absint($_POST['source_id'])
        : 0;

    if ($source_id <= 0) {
        wp_die(
            esc_html__(
                'Invalid source ID.',
                'ai-job-pipeline'
            )
        );
    }

    check_admin_referer(
        'aijp_delete_source_' . $source_id
    );

    $source = AIJP_Source_Repository::get($source_id);

    if (!$source) {
        wp_safe_redirect(
            add_query_arg(
                [
                    'page'   => 'aijp-sources',
                    'status' => 'not_found',
                ],
                admin_url('admin.php')
            )
        );

        exit;
    }

    $deleted = AIJP_Source_Repository::delete($source_id);

    wp_safe_redirect(
        add_query_arg(
            [
                'page'   => 'aijp-sources',
                'status' => $deleted ? 'deleted' : 'delete_failed',
            ],
            admin_url('admin.php')
        )
    );

    exit;
}

/**
 * Handle source enable/disable.
 *
 * This is a write operation, therefore it requires:
 * - manage_sources capability;
 * - POST;
 * - nonce.
 */
if (
    'POST' === $_SERVER['REQUEST_METHOD'] &&
    isset($_POST['aijp_toggle_source'])
) {
    if (!current_user_can(AIJP_Roles::CAP_MANAGE_SOURCES)) {
        wp_die(
            esc_html__(
                'You do not have permission to modify sources.',
                'ai-job-pipeline'
            )
        );
    }

    $source_id = isset($_POST['source_id'])
        ? absint($_POST['source_id'])
        : 0;

    if ($source_id <= 0) {
        wp_die(
            esc_html__(
                'Invalid source ID.',
                'ai-job-pipeline'
            )
        );
    }

    check_admin_referer(
        'aijp_toggle_source_' . $source_id
    );

    $source = AIJP_Source_Repository::get($source_id);

    if (!$source) {
        wp_safe_redirect(
            add_query_arg(
                [
                    'page'   => 'aijp-sources',
                    'status' => 'not_found',
                ],
                admin_url('admin.php')
            )
        );

        exit;
    }

    $new_enabled = empty($source->enabled);

    $updated = AIJP_Source_Repository::update(
        $source_id,
        [
            'enabled' => $new_enabled ? 1 : 0,
        ]
    );

    wp_safe_redirect(
        add_query_arg(
            [
                'page'   => 'aijp-sources',
                'status' => $updated ? 'updated' : 'update_failed',
            ],
            admin_url('admin.php')
        )
    );

    exit;
}

$sources = AIJP_Source_Repository::get_all();

$status = isset($_GET['status'])
    ? sanitize_key(wp_unslash($_GET['status']))
    : '';

?>

<div class="wrap">

    <h1 class="wp-heading-inline">
        <?php echo esc_html__(
            'Sources',
            'ai-job-pipeline'
        ); ?>
    </h1>

    <a
        href="<?php echo esc_url(
            admin_url('admin.php?page=aijp-add-source')
        ); ?>"
        class="page-title-action"
    >
        <?php echo esc_html__(
            'Add source',
            'ai-job-pipeline'
        ); ?>
    </a>

    <form method="post" style="display:inline-block;">
        <?php wp_nonce_field('aijp_run_import'); ?>
        <input type="hidden" name="aijp_run_import" value="1">
        <button type="submit" class="page-title-action">
            <?php echo esc_html__(
                'Run import now',
                'ai-job-pipeline'
            ); ?>
        </button>
    </form>

    <hr class="wp-header-end">

    <?php if (
        in_array(
            $status,
            ['import_complete', 'import_partial'],
            true
        )
    ) : ?>

        <div class="notice <?php echo esc_attr(
            'import_complete' === $status
                ? 'notice-success'
                : 'notice-warning'
        ); ?> is-dismissible">
            <p>
                <?php
                printf(
                    esc_html__(
                        'Import finished. Found: %1$d, added: %2$d, duplicates: %3$d, failed: %4$d.',
                        'ai-job-pipeline'
                    ),
                    absint($_GET['found'] ?? 0),
                    absint($_GET['added'] ?? 0),
                    absint($_GET['duplicates'] ?? 0),
                    absint($_GET['failed'] ?? 0)
                );
                ?>
            </p>
        </div>

    <?php elseif ('deleted' === $status) : ?>

        <div class="notice notice-success is-dismissible">
            <p>
                <?php echo esc_html__(
                    'Source deleted.',
                    'ai-job-pipeline'
                ); ?>
            </p>
        </div>

    <?php elseif ('delete_failed' === $status) : ?>

        <div class="notice notice-error is-dismissible">
            <p>
                <?php echo esc_html__(
                    'Source could not be deleted.',
                    'ai-job-pipeline'
                ); ?>
            </p>
        </div>

    <?php elseif ('updated' === $status) : ?>

        <div class="notice notice-success is-dismissible">
            <p>
                <?php echo esc_html__(
                    'Source updated.',
                    'ai-job-pipeline'
                ); ?>
            </p>
        </div>

    <?php elseif ('update_failed' === $status) : ?>

        <div class="notice notice-error is-dismissible">
            <p>
                <?php echo esc_html__(
                    'Source could not be updated.',
                    'ai-job-pipeline'
                ); ?>
            </p>
        </div>

    <?php elseif ('not_found' === $status) : ?>

        <div class="notice notice-error is-dismissible">
            <p>
                <?php echo esc_html__(
                    'Source not found.',
                    'ai-job-pipeline'
                ); ?>
            </p>
        </div>

    <?php endif; ?>

    <?php if (empty($sources)) : ?>

        <div class="notice notice-info">

            <p>
                <?php echo esc_html__(
                    'No sources have been configured yet.',
                    'ai-job-pipeline'
                ); ?>
            </p>

        </div>

    <?php else : ?>

        <table class="widefat fixed striped">

            <thead>

                <tr>

                    <th style="width:60px;">
                        <?php echo esc_html__(
                            'ID',
                            'ai-job-pipeline'
                        ); ?>
                    </th>

                    <th>
                        <?php echo esc_html__(
                            'Name',
                            'ai-job-pipeline'
                        ); ?>
                    </th>

                    <th>
                        <?php echo esc_html__(
                            'URL',
                            'ai-job-pipeline'
                        ); ?>
                    </th>

                    <th style="width:120px;">
                        <?php echo esc_html__(
                            'Type',
                            'ai-job-pipeline'
                        ); ?>
                    </th>

                    <th style="width:110px;">
                        <?php echo esc_html__(
                            'Enabled',
                            'ai-job-pipeline'
                        ); ?>
                    </th>

                    <th style="width:180px;">
                        <?php echo esc_html__(
                            'Last checked',
                            'ai-job-pipeline'
                        ); ?>
                    </th>

                    <th style="width:180px;">
                        <?php echo esc_html__(
                            'Actions',
                            'ai-job-pipeline'
                        ); ?>
                    </th>

                </tr>

            </thead>

            <tbody>

                <?php foreach ($sources as $source) : ?>

                    <?php
                    $source_id = absint($source->id);

                    $edit_url = add_query_arg(
                        [
                            'page' => 'aijp-edit-source',
                            'id'   => $source_id,
                        ],
                        admin_url('admin.php')
                    );

                    $enabled = !empty($source->enabled);

                    $type = !empty($source->source_type)
                        ? $source->source_type
                        : 'rss';

                    $last_checked = !empty(
                        $source->last_import_at
                    )
                        ? $source->last_import_at
                        : '—';

                    $source_url = !empty($source->url)
                        ? $source->url
                        : '';
                    ?>

                    <tr>

                        <td>
                            <?php echo esc_html($source_id); ?>
                        </td>

                        <td>

                            <strong>

                                <a
                                    href="<?php echo esc_url(
                                        $edit_url
                                    ); ?>"
                                >
                                    <?php echo esc_html(
                                        $source->name
                                    ); ?>
                                </a>

                            </strong>

                            <div class="row-actions">

                                <span>

                                    <a
                                        href="<?php echo esc_url(
                                            $edit_url
                                        ); ?>"
                                    >
                                        <?php echo esc_html__(
                                            'Edit',
                                            'ai-job-pipeline'
                                        ); ?>
                                    </a>

                                </span>

                            </div>

                        </td>

                        <td>

                            <?php if ($source_url) : ?>

                                <a
                                    href="<?php echo esc_url(
                                        $source_url
                                    ); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <?php echo esc_html(
                                        $source_url
                                    ); ?>
                                </a>

                            <?php else : ?>

                                —

                            <?php endif; ?>

                        </td>

                        <td>
                            <?php echo esc_html($type); ?>
                        </td>

                        <td>

                            <?php if ($enabled) : ?>

                                <span>
                                    <?php echo esc_html__(
                                        'Yes',
                                        'ai-job-pipeline'
                                    ); ?>
                                </span>

                            <?php else : ?>

                                <span>
                                    <?php echo esc_html__(
                                        'No',
                                        'ai-job-pipeline'
                                    ); ?>
                                </span>

                            <?php endif; ?>

                        </td>

                        <td>
                            <?php echo esc_html(
                                $last_checked
                            ); ?>
                        </td>

                        <td>

                            <a
                                class="button button-small"
                                href="<?php echo esc_url(
                                    $edit_url
                                ); ?>"
                            >
                                <?php echo esc_html__(
                                    'Edit',
                                    'ai-job-pipeline'
                                ); ?>
                            </a>

                            <form
                                method="post"
                                style="
                                    display:inline-block;
                                    margin-left:4px;
                                "
                            >

                                <?php
                                wp_nonce_field(
                                    'aijp_toggle_source_' . $source_id
                                );
                                ?>

                                <input
                                    type="hidden"
                                    name="aijp_toggle_source"
                                    value="1"
                                >

                                <input
                                    type="hidden"
                                    name="source_id"
                                    value="<?php echo esc_attr(
                                        $source_id
                                    ); ?>"
                                >

                                <button
                                    type="submit"
                                    class="button button-small"
                                >
                                    <?php
                                    echo esc_html(
                                        $enabled
                                            ? __(
                                                'Disable',
                                                'ai-job-pipeline'
                                            )
                                            : __(
                                                'Enable',
                                                'ai-job-pipeline'
                                            )
                                    );
                                    ?>
                                </button>

                            </form>

                            <form
                                method="post"
                                style="
                                    display:inline-block;
                                    margin-left:4px;
                                "
                            >

                                <?php
                                wp_nonce_field(
                                    'aijp_delete_source_' . $source_id
                                );
                                ?>

                                <input
                                    type="hidden"
                                    name="aijp_delete_source"
                                    value="1"
                                >

                                <input
                                    type="hidden"
                                    name="source_id"
                                    value="<?php echo esc_attr(
                                        $source_id
                                    ); ?>"
                                >

                                <button
                                    type="submit"
                                    class="button button-small"
                                    onclick="return window.confirm(
                                        <?php
                                        echo esc_js(
                                            __(
                                                'Delete this source? This action cannot be undone.',
                                                'ai-job-pipeline'
                                            )
                                        );
                                        ?>
                                    );"
                                >
                                    <?php echo esc_html__(
                                        'Delete',
                                        'ai-job-pipeline'
                                    ); ?>
                                </button>

                            </form>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    <?php endif; ?>

</div>
