<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin integration.
 *
 * Registers plugin admin pages and loads page templates.
 */
class AIJP_Admin
{
    /**
     * Register admin menu.
     *
     * @return void
     */
    public function register_menu(): void
    {
        $view_jobs = AIJP_Roles::CAP_VIEW_JOBS;
        $manage_sources = AIJP_Roles::CAP_MANAGE_SOURCES;
        $manage_settings = AIJP_Roles::CAP_MANAGE_SETTINGS;

        add_menu_page(
            __(
                'AI Job Pipeline',
                'ai-job-pipeline'
            ),
            __(
                'AI Job Pipeline',
                'ai-job-pipeline'
            ),
            $view_jobs,
            'aijp-dashboard',
            [
                $this,
                'dashboard_page',
            ],
            'dashicons-networking',
            56
        );

        add_submenu_page(
            'aijp-dashboard',
            __(
                'Dashboard',
                'ai-job-pipeline'
            ),
            __(
                'Dashboard',
                'ai-job-pipeline'
            ),
            $view_jobs,
            'aijp-dashboard',
            [
                $this,
                'dashboard_page',
            ]
        );

        add_submenu_page(
            'aijp-dashboard',
            __(
                'Jobs',
                'ai-job-pipeline'
            ),
            __(
                'Jobs',
                'ai-job-pipeline'
            ),
            $view_jobs,
            'aijp-jobs',
            [
                $this,
                'jobs_page',
            ]
        );

        add_submenu_page(
            'aijp-dashboard',
            __(
                'Sources',
                'ai-job-pipeline'
            ),
            __(
                'Sources',
                'ai-job-pipeline'
            ),
            $manage_sources,
            'aijp-sources',
            [
                $this,
                'sources_page',
            ]
        );

        /*
         * Hidden page.
         *
         * Used for adding a new source.
         */
        add_submenu_page(
            null,
            __(
                'Add Source',
                'ai-job-pipeline'
            ),
            __(
                'Add Source',
                'ai-job-pipeline'
            ),
            $manage_sources,
            'aijp-add-source',
            [
                $this,
                'add_source_page',
            ]
        );

        /*
         * Hidden page.
         *
         * Used for editing an existing source.
         */
        add_submenu_page(
            null,
            __(
                'Edit Source',
                'ai-job-pipeline'
            ),
            __(
                'Edit Source',
                'ai-job-pipeline'
            ),
            $manage_sources,
            'aijp-edit-source',
            [
                $this,
                'edit_source_page',
            ]
        );

        /*
         * Hidden page.
         *
         * Used for viewing job details.
         */
        add_submenu_page(
            null,
            __(
                'Job Details',
                'ai-job-pipeline'
            ),
            __(
                'Job Details',
                'ai-job-pipeline'
            ),
            $view_jobs,
            'aijp-job',
            [
                $this,
                'job_page',
            ]
        );

        add_submenu_page(
            'aijp-dashboard',
            __(
                'AI Queue',
                'ai-job-pipeline'
            ),
            __(
                'AI Queue',
                'ai-job-pipeline'
            ),
            $manage_settings,
            'aijp-ai-queue',
            [
                $this,
                'ai_queue_page',
            ]
        );

        add_submenu_page(
            'aijp-dashboard',
            __(
                'Logs',
                'ai-job-pipeline'
            ),
            __(
                'Logs',
                'ai-job-pipeline'
            ),
            $manage_settings,
            'aijp-logs',
            [
                $this,
                'logs_page',
            ]
        );

        add_submenu_page(
            'aijp-dashboard',
            __(
                'Settings',
                'ai-job-pipeline'
            ),
            __(
                'Settings',
                'ai-job-pipeline'
            ),
            $manage_settings,
            'aijp-settings',
            [
                $this,
                'settings_page',
            ]
        );
    }

    /**
     * Dashboard page.
     *
     * @return void
     */
    public function dashboard_page(): void
    {
        $this->render_page(
            'dashboard.php',
            AIJP_Roles::CAP_VIEW_JOBS
        );
    }

    /**
     * Jobs page.
     *
     * @return void
     */
    public function jobs_page(): void
    {
        $this->render_page(
            'jobs.php',
            AIJP_Roles::CAP_VIEW_JOBS
        );
    }

    /**
     * Job details page.
     *
     * @return void
     */
    public function job_page(): void
    {
        $this->render_page(
            'job.php',
            AIJP_Roles::CAP_VIEW_JOBS
        );
    }

    /**
     * Sources page.
     *
     * @return void
     */
    public function sources_page(): void
    {
        $this->render_page(
            'sources.php',
            AIJP_Roles::CAP_MANAGE_SOURCES
        );
    }

    /**
     * Add source page.
     *
     * @return void
     */
    public function add_source_page(): void
    {
        $this->render_page(
            'add-source.php',
            AIJP_Roles::CAP_MANAGE_SOURCES
        );
    }

    /**
     * Edit source page.
     *
     * @return void
     */
    public function edit_source_page(): void
    {
        $this->render_page(
            'edit-source.php',
            AIJP_Roles::CAP_MANAGE_SOURCES
        );
    }

    /**
     * AI queue page.
     *
     * @return void
     */
    public function ai_queue_page(): void
    {
        $this->render_page(
            'ai-queue.php',
            AIJP_Roles::CAP_MANAGE_SETTINGS
        );
    }

    /**
     * Settings page.
     *
     * @return void
     */
    public function settings_page(): void
    {
        $this->render_page(
            'settings.php',
            AIJP_Roles::CAP_MANAGE_SETTINGS
        );
    }

    /**
     * Logs page.
     *
     * @return void
     */
    public function logs_page(): void
    {
        $this->render_page(
            'logs.php',
            AIJP_Roles::CAP_MANAGE_SETTINGS
        );
    }

    /**
     * Render admin page.
     *
     * @param string $page       Page filename.
     * @param string $capability Required capability.
     *
     * @return void
     */
    private function render_page(
        string $page,
        string $capability
    ): void {
        if (
            !current_user_can(
                $capability
            )
        ) {
            wp_die(
                esc_html__(
                    'You do not have permission to access this page.',
                    'ai-job-pipeline'
                )
            );
        }

        $file =
            AIJP_PLUGIN_PATH .
            'admin/pages/' .
            ltrim(
                $page,
                '/'
            );

        if (!file_exists($file)) {
            wp_die(
                esc_html__(
                    'Admin page file was not found.',
                    'ai-job-pipeline'
                )
            );
        }

        require $file;
    }
}
