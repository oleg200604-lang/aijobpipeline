<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add source page.
 *
 * Access:
 * - aij_manage_sources is required.
 *
 * Security:
 * - capability is checked before rendering;
 * - capability is checked again before processing POST;
 * - nonce protects the form;
 * - all input is sanitized;
 * - URL is validated and normalized with esc_url_raw();
 * - output is escaped.
 */

if (!current_user_can(AIJP_Roles::CAP_MANAGE_SOURCES)) {
    wp_die(
        esc_html__(
            'You do not have permission to manage sources.',
            'ai-job-pipeline'
        )
    );
}

$errors = [];

$old = [
    'name'        => '',
    'url'         => '',
    'country'     => '',
    'language'    => '',
    'source_type' => 'RSS',
    'enabled'     => 1,
];

/*
 * Process form submission.
 */
if (
    'POST' === $_SERVER['REQUEST_METHOD'] &&
    isset($_POST['aijp_add_source'])
) {
    /*
     * Never rely on the fact that the form was visible to the user.
     * Check the capability again at the write boundary.
     */
    if (!current_user_can(AIJP_Roles::CAP_MANAGE_SOURCES)) {
        wp_die(
            esc_html__(
                'You do not have permission to create sources.',
                'ai-job-pipeline'
            )
        );
    }

    check_admin_referer('aijp_add_source');

    $old['name'] = isset($_POST['name'])
        ? sanitize_text_field(
            wp_unslash($_POST['name'])
        )
        : '';

    $old['url'] = isset($_POST['url'])
        ? esc_url_raw(
            wp_unslash($_POST['url'])
        )
        : '';

    $old['country'] = isset($_POST['country'])
        ? sanitize_text_field(
            wp_unslash($_POST['country'])
        )
        : '';

    $old['language'] = isset($_POST['language'])
        ? sanitize_text_field(
            wp_unslash($_POST['language'])
        )
        : '';

    $old['source_type'] = isset($_POST['source_type'])
        ? sanitize_text_field(
            wp_unslash($_POST['source_type'])
        )
        : 'RSS';

    $old['enabled'] = isset($_POST['enabled'])
        ? 1
        : 0;

    /*
     * Validate name.
     */
    if ('' === $old['name']) {
        $errors[] = __(
            'Source name is required.',
            'ai-job-pipeline'
        );
    }

    /*
     * Validate URL.
     */
    if ('' === $old['url']) {
        $errors[] = __(
            'Source URL is required.',
            'ai-job-pipeline'
        );
    } elseif (!wp_http_validate_url($old['url'])) {
        $errors[] = __(
            'Please enter a valid HTTP or HTTPS source URL.',
            'ai-job-pipeline'
        );
    }

    /*
     * Keep source types constrained to the values currently supported
     * by the existing source form/repository.
     */
    $allowed_source_types = [
        'API',
        'RSS',
        'Parser',
    ];

    if (
        !in_array(
            $old['source_type'],
            $allowed_source_types,
            true
        )
    ) {
        $errors[] = __(
            'Invalid source type.',
            'ai-job-pipeline'
        );
    }

    /*
     * Create source only after all validation has succeeded.
     */
    if (empty($errors)) {

        $source_id = AIJP_Source_Repository::insert(
            [
                'name'        => $old['name'],
                'url'         => $old['url'],
                'country'     => $old['country'],
                'language'    => $old['language'],
                'source_type' => $old['source_type'],
                'enabled'     => $old['enabled'],
            ]
        );

        if ($source_id) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'   => 'aijp-sources',
                        'status' => 'created',
                    ],
                    admin_url('admin.php')
                )
            );

            exit;
        }

        $errors[] = __(
            'The source could not be created.',
            'ai-job-pipeline'
        );
    }
}

?>

<div class="wrap">

    <h1>
        <?php echo esc_html__(
            'Add Source',
            'ai-job-pipeline'
        ); ?>
    </h1>

    <p>
        <a
            href="<?php echo esc_url(
                admin_url('admin.php?page=aijp-sources')
            ); ?>"
        >
            &larr;
            <?php echo esc_html__(
                'Back to Sources',
                'ai-job-pipeline'
            ); ?>
        </a>
    </p>

    <?php if (!empty($errors)) : ?>

        <div class="notice notice-error">

            <p>
                <strong>
                    <?php echo esc_html__(
                        'The source could not be saved:',
                        'ai-job-pipeline'
                    ); ?>
                </strong>
            </p>

            <ul>

                <?php foreach ($errors as $error) : ?>

                    <li>
                        <?php echo esc_html($error); ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>

    <form method="post">

        <?php wp_nonce_field('aijp_add_source'); ?>

        <input
            type="hidden"
            name="aijp_add_source"
            value="1"
        >

        <table class="form-table">

            <tr>

                <th scope="row">

                    <label for="aijp-source-name">
                        <?php echo esc_html__(
                            'Name',
                            'ai-job-pipeline'
                        ); ?>
                    </label>

                </th>

                <td>

                    <input
                        type="text"
                        id="aijp-source-name"
                        name="name"
                        class="regular-text"
                        value="<?php echo esc_attr(
                            $old['name']
                        ); ?>"
                        required
                    >

                    <p class="description">
                        <?php echo esc_html__(
                            'A human-readable name for this source.',
                            'ai-job-pipeline'
                        ); ?>
                    </p>

                </td>

            </tr>

            <tr>

                <th scope="row">

                    <label for="aijp-source-url">
                        <?php echo esc_html__(
                            'URL',
                            'ai-job-pipeline'
                        ); ?>
                    </label>

                </th>

                <td>

                    <input
                        type="url"
                        id="aijp-source-url"
                        name="url"
                        class="regular-text"
                        value="<?php echo esc_attr(
                            $old['url']
                        ); ?>"
                        required
                    >

                    <p class="description">
                        <?php echo esc_html__(
                            'HTTP or HTTPS feed/API URL.',
                            'ai-job-pipeline'
                        ); ?>
                    </p>

                </td>

            </tr>

            <tr>

                <th scope="row">

                    <label for="aijp-source-country">
                        <?php echo esc_html__(
                            'Country',
                            'ai-job-pipeline'
                        ); ?>
                    </label>

                </th>

                <td>

                    <input
                        type="text"
                        id="aijp-source-country"
                        name="country"
                        class="regular-text"
                        value="<?php echo esc_attr(
                            $old['country']
                        ); ?>"
                    >

                </td>

            </tr>

            <tr>

                <th scope="row">

                    <label for="aijp-source-language">
                        <?php echo esc_html__(
                            'Language',
                            'ai-job-pipeline'
                        ); ?>
                    </label>

                </th>

                <td>

                    <input
                        type="text"
                        id="aijp-source-language"
                        name="language"
                        class="regular-text"
                        value="<?php echo esc_attr(
                            $old['language']
                        ); ?>"
                    >

                </td>

            </tr>

            <tr>

                <th scope="row">

                    <label for="aijp-source-type">
                        <?php echo esc_html__(
                            'Type',
                            'ai-job-pipeline'
                        ); ?>
                    </label>

                </th>

                <td>

                    <select
                        id="aijp-source-type"
                        name="source_type"
                    >

                        <option
                            value="RSS"
                            <?php selected(
                                $old['source_type'],
                                'RSS'
                            ); ?>
                        >
                            RSS
                        </option>

                        <option
                            value="API"
                            <?php selected(
                                $old['source_type'],
                                'API'
                            ); ?>
                        >
                            API
                        </option>

                        <option
                            value="Parser"
                            <?php selected(
                                $old['source_type'],
                                'Parser'
                            ); ?>
                        >
                            Parser
                        </option>

                    </select>

                </td>

            </tr>

            <tr>

                <th scope="row">
                    <?php echo esc_html__(
                        'Enabled',
                        'ai-job-pipeline'
                    ); ?>
                </th>

                <td>

                    <label for="aijp-source-enabled">

                        <input
                            type="checkbox"
                            id="aijp-source-enabled"
                            name="enabled"
                            value="1"
                            <?php checked(
                                $old['enabled'],
                                1
                            ); ?>
                        >

                        <?php echo esc_html__(
                            'Enable this source',
                            'ai-job-pipeline'
                        ); ?>

                    </label>

                    <p class="description">
                        <?php echo esc_html__(
                            'Enabled sources can be processed by the import pipeline.',
                            'ai-job-pipeline'
                        ); ?>
                    </p>

                </td>

            </tr>

        </table>

        <?php
        submit_button(
            __('Save source', 'ai-job-pipeline')
        );
        ?>

    </form>

</div>