<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Edit Source page.
 *
 * Capability:
 * - aij_manage_sources
 *
 * Security:
 * - capability checked before rendering;
 * - capability checked again at the write boundary;
 * - source-specific nonce;
 * - input sanitization;
 * - URL validation;
 * - allow-list validation for source type;
 * - escaped output;
 * - redirect after successful POST.
 */

if (!current_user_can(AIJP_Roles::CAP_MANAGE_SOURCES)) {
    wp_die(
        esc_html__(
            'You do not have permission to manage sources.',
            'ai-job-pipeline'
        )
    );
}

/*
 * Get source ID.
 */
$source_id = isset($_GET['id'])
    ? absint($_GET['id'])
    : 0;

if ($source_id <= 0) {
    wp_die(
        esc_html__(
            'Invalid source ID.',
            'ai-job-pipeline'
        )
    );
}

/*
 * Load existing source.
 */
$source = AIJP_Source_Repository::get($source_id);

if (!$source) {
    wp_die(
        esc_html__(
            'Source not found.',
            'ai-job-pipeline'
        )
    );
}

/*
 * Form state.
 *
 * Keep the submitted values here when validation fails so the user
 * does not lose their input.
 */
$values = [
    'name'        => isset($source->name)
        ? (string) $source->name
        : '',

    'url'         => isset($source->url)
        ? (string) $source->url
        : '',

    'country'     => isset($source->country)
        ? (string) $source->country
        : '',

    'language'    => isset($source->language)
        ? (string) $source->language
        : '',

    'source_type' => isset($source->source_type)
        ? (string) $source->source_type
        : 'RSS',

    'enabled'     => !empty($source->enabled)
        ? 1
        : 0,
];

$errors = [];

/*
 * Process update.
 */
if (
    'POST' === $_SERVER['REQUEST_METHOD'] &&
    isset($_POST['aijp_update_source'])
) {
    /*
     * IMPORTANT:
     * Never rely on the capability check that protected the page.
     *
     * A user can manually send a POST request without ever loading
     * the form, therefore authorization is checked again here.
     */
    if (!current_user_can(AIJP_Roles::CAP_MANAGE_SOURCES)) {
        wp_die(
            esc_html__(
                'You do not have permission to update sources.',
                'ai-job-pipeline'
            )
        );
    }

    /*
     * Verify a nonce bound to this particular source.
     */
    check_admin_referer(
        'aijp_update_source_' . $source_id
    );

    /*
     * Read and sanitize submitted values.
     */
    $values['name'] = isset($_POST['name'])
        ? sanitize_text_field(
            wp_unslash($_POST['name'])
        )
        : '';

    $values['url'] = isset($_POST['url'])
        ? esc_url_raw(
            wp_unslash($_POST['url'])
        )
        : '';

    $values['country'] = isset($_POST['country'])
        ? sanitize_text_field(
            wp_unslash($_POST['country'])
        )
        : '';

    $values['language'] = isset($_POST['language'])
        ? sanitize_text_field(
            wp_unslash($_POST['language'])
        )
        : '';

    $values['source_type'] = isset($_POST['source_type'])
        ? sanitize_text_field(
            wp_unslash($_POST['source_type'])
        )
        : '';

    $values['enabled'] = isset($_POST['enabled'])
        ? 1
        : 0;

    /*
     * Validate name.
     */
    if ('' === $values['name']) {
        $errors[] = __(
            'Source name is required.',
            'ai-job-pipeline'
        );
    }

    /*
     * Validate URL.
     */
    if ('' === $values['url']) {
        $errors[] = __(
            'Source URL is required.',
            'ai-job-pipeline'
        );
    } elseif (!wp_http_validate_url($values['url'])) {
        $errors[] = __(
            'Please enter a valid HTTP or HTTPS source URL.',
            'ai-job-pipeline'
        );
    }

    /*
     * Validate source type against an allow-list.
     */
    $allowed_source_types = [
        'API',
        'RSS',
        'Parser',
    ];

    if (
        !in_array(
            $values['source_type'],
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
     * Perform update only after validation succeeds.
     */
    if (empty($errors)) {

        $updated = AIJP_Source_Repository::update(
            $source_id,
            [
                'name'        => $values['name'],
                'url'         => $values['url'],
                'country'     => $values['country'],
                'language'    => $values['language'],
                'source_type' => $values['source_type'],
                'enabled'     => $values['enabled'],
            ]
        );

        if ($updated) {

            AIJP_Logger::info(
                sprintf(
                    'Source #%d updated by user #%d.',
                    $source_id,
                    get_current_user_id()
                )
            );

            /*
             * PRG pattern:
             * POST -> redirect -> GET
             *
             * Prevents accidental duplicate submissions on refresh.
             */
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'   => 'aijp-edit-source',
                        'id'     => $source_id,
                        'status' => 'updated',
                    ],
                    admin_url('admin.php')
                )
            );

            exit;
        }

        $errors[] = __(
            'The source could not be updated.',
            'ai-job-pipeline'
        );
    }
}

?>

<div class="wrap">

    <h1>
        <?php echo esc_html__(
            'Edit Source',
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

        <?php
        wp_nonce_field(
            'aijp_update_source_' . $source_id
        );
        ?>

        <input
            type="hidden"
            name="aijp_update_source"
            value="1"
        >

        <table class="form-table">

            <tr>

                <th scope="row">

                    <label for="aijp-source-id">
                        <?php echo esc_html__(
                            'ID',
                            'ai-job-pipeline'
                        ); ?>
                    </label>

                </th>

                <td>

                    <input
                        type="text"
                        id="aijp-source-id"
                        class="regular-text"
                        value="<?php echo esc_attr(
                            $source_id
                        ); ?>"
                        readonly
                    >

                </td>

            </tr>

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
                            $values['name']
                        ); ?>"
                        required
                    >

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
                            $values['url']
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
                            $values['country']
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
                            $values['language']
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
                                $values['source_type'],
                                'RSS'
                            ); ?>
                        >
                            RSS
                        </option>

                        <option
                            value="API"
                            <?php selected(
                                $values['source_type'],
                                'API'
                            ); ?>
                        >
                            API
                        </option>

                        <option
                            value="Parser"
                            <?php selected(
                                $values['source_type'],
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
                                $values['enabled'],
                                1
                            ); ?>
                        >

                        <?php echo esc_html__(
                            'Enable this source',
                            'ai-job-pipeline'
                        ); ?>

                    </label>

                </td>

            </tr>

        </table>

        <?php
        submit_button(
            __('Save changes', 'ai-job-pipeline')
        );
        ?>

    </form>

</div>