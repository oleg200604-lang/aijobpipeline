<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI Job Pipeline roles and capabilities.
 *
 * This class is responsible only for:
 * - defining plugin roles;
 * - defining plugin capabilities;
 * - installing/synchronizing those roles and capabilities;
 * - checking current-user capabilities.
 *
 * It does not decide whether a user owns a particular job.
 * Ownership checks belong to the authorization/workflow layer.
 */
class AIJP_Roles
{
    /**
     * Plugin-specific roles.
     */
    public const ROLE_ADMIN = 'aijp_admin';
    public const ROLE_EXECUTOR = 'aijp_executor';
    public const ROLE_OBSERVER = 'aijp_observer';

    /**
     * Plugin capabilities.
     */
    public const CAP_MANAGE_SETTINGS = 'aij_manage_settings';
    public const CAP_MANAGE_SOURCES = 'aij_manage_sources';
    public const CAP_VIEW_JOBS = 'aij_view_jobs';
    public const CAP_EDIT_JOBS = 'aij_edit_jobs';
    public const CAP_VIEW_FINANCE = 'aij_view_finance';
    public const CAP_MANAGE_USERS = 'aij_manage_users';

    /**
     * Return every capability owned by this plugin.
     *
     * This list is also used when synchronizing custom roles.
     *
     * @return array<string>
     */
    public static function capabilities(): array
    {
        return [
            self::CAP_MANAGE_SETTINGS,
            self::CAP_MANAGE_SOURCES,
            self::CAP_VIEW_JOBS,
            self::CAP_EDIT_JOBS,
            self::CAP_VIEW_FINANCE,
            self::CAP_MANAGE_USERS,
        ];
    }

    /**
     * Install or synchronize plugin roles and capabilities.
     *
     * This method is intentionally idempotent.
     * It is safe to call during every plugin activation.
     *
     * @return void
     */
    public static function install(): void
    {
        self::ensure_admin_capabilities();
        self::ensure_roles();
        self::synchronize_custom_roles();
    }

    /**
     * Give all plugin capabilities to the normal WordPress administrator role.
     *
     * We intentionally do not remove any capabilities from the administrator.
     *
     * @return void
     */
    private static function ensure_admin_capabilities(): void
    {
        $role = get_role('administrator');

        if (!$role) {
            return;
        }

        foreach (self::capabilities() as $capability) {
            $role->add_cap($capability);
        }
    }

    /**
     * Create plugin-specific roles if they do not exist.
     *
     * add_role() does not replace an already existing role, therefore
     * synchronization is handled separately by synchronize_custom_roles().
     *
     * @return void
     */
    private static function ensure_roles(): void
    {
        add_role(
            self::ROLE_ADMIN,
            __('AIJP Administrator', 'ai-job-pipeline'),
            self::admin_capabilities()
        );

        add_role(
            self::ROLE_EXECUTOR,
            __('AIJP Executor', 'ai-job-pipeline'),
            self::executor_capabilities()
        );

        add_role(
            self::ROLE_OBSERVER,
            __('AIJP Observer', 'ai-job-pipeline'),
            self::observer_capabilities()
        );
    }

    /**
     * Synchronize capabilities of all plugin-specific roles.
     *
     * This is important when a role definition changes between plugin versions.
     * We must not leave obsolete plugin capabilities on a role.
     *
     * Standard WordPress capabilities are not touched here.
     *
     * @return void
     */
    private static function synchronize_custom_roles(): void
    {
        $roles = [
            self::ROLE_ADMIN => self::admin_capabilities(),
            self::ROLE_EXECUTOR => self::executor_capabilities(),
            self::ROLE_OBSERVER => self::observer_capabilities(),
        ];

        foreach ($roles as $role_name => $desired_capabilities) {
            $role = get_role($role_name);

            if (!$role) {
                continue;
            }

            /*
             * Every plugin role needs basic WordPress read access.
             */
            $role->add_cap('read');

            /*
             * Remove plugin capabilities that this particular role
             * must not have.
             */
            foreach (self::capabilities() as $capability) {
                if (
                    !isset($desired_capabilities[$capability]) ||
                    !$desired_capabilities[$capability]
                ) {
                    $role->remove_cap($capability);
                }
            }

            /*
             * Add the capabilities explicitly granted to this role.
             */
            foreach ($desired_capabilities as $capability => $grant) {
                if ($grant) {
                    $role->add_cap($capability);
                }
            }
        }
    }

    /**
     * Capabilities for AIJP Administrator.
     *
     * The AIJP Administrator is the plugin administrator.
     *
     * @return array<string,bool>
     */
    private static function admin_capabilities(): array
    {
        return [
            'read' => true,

            self::CAP_MANAGE_SETTINGS => true,
            self::CAP_MANAGE_SOURCES  => true,
            self::CAP_VIEW_JOBS       => true,
            self::CAP_EDIT_JOBS       => true,
            self::CAP_VIEW_FINANCE    => true,
            self::CAP_MANAGE_USERS    => true,
        ];
    }

    /**
     * Capabilities for AIJP Executor.
     *
     * The executor may:
     * - view jobs;
     * - edit jobs that authorization logic determines to be theirs;
     * - view financial information that authorization logic determines
     *   to belong to their assigned jobs.
     *
     * The capability itself does NOT grant access to every job/finance record.
     *
     * @return array<string,bool>
     */
    private static function executor_capabilities(): array
    {
        return [
            'read' => true,

            self::CAP_VIEW_JOBS    => true,
            self::CAP_EDIT_JOBS    => true,
            self::CAP_VIEW_FINANCE => true,
        ];
    }

    /**
     * Capabilities for AIJP Observer.
     *
     * Observer is read-only.
     *
     * The role must not receive:
     * - settings management;
     * - source management;
     * - job editing;
     * - user management.
     *
     * Finance visibility is intentionally granted because the specification
     * allows an observer to see aggregate financial information.
     *
     * @return array<string,bool>
     */
    private static function observer_capabilities(): array
    {
        return [
            'read' => true,

            self::CAP_VIEW_JOBS    => true,
            self::CAP_VIEW_FINANCE => true,
        ];
    }

    /**
     * Check whether the current user can manage plugin settings.
     *
     * @return bool
     */
    public static function can_manage_settings(): bool
    {
        return current_user_can(self::CAP_MANAGE_SETTINGS);
    }

    /**
     * Check whether the current user can manage sources.
     *
     * @return bool
     */
    public static function can_manage_sources(): bool
    {
        return current_user_can(self::CAP_MANAGE_SOURCES);
    }

    /**
     * Check whether the current user can view jobs.
     *
     * @return bool
     */
    public static function can_view_jobs(): bool
    {
        return current_user_can(self::CAP_VIEW_JOBS);
    }

    /**
     * Check whether the current user can edit jobs.
     *
     * @return bool
     */
    public static function can_edit_jobs(): bool
    {
        return current_user_can(self::CAP_EDIT_JOBS);
    }

    /**
     * Check whether the current user can view finance.
     *
     * @return bool
     */
    public static function can_view_finance(): bool
    {
        return current_user_can(self::CAP_VIEW_FINANCE);
    }

    /**
     * Check whether the current user can manage users.
     *
     * @return bool
     */
    public static function can_manage_users(): bool
    {
        return current_user_can(self::CAP_MANAGE_USERS);
    }

    /**
     * Check whether the current user has the plugin administrator role.
     *
     * This is useful for authorization decisions that are specifically
     * about the plugin role rather than a generic capability.
     *
     * @return bool
     */
    public static function is_plugin_admin(): bool
    {
        $user = wp_get_current_user();

        if (!$user || empty($user->roles)) {
            return false;
        }

        return in_array(
            self::ROLE_ADMIN,
            (array) $user->roles,
            true
        );
    }

    /**
     * Check whether the current user has the executor role.
     *
     * @return bool
     */
    public static function is_executor(): bool
    {
        $user = wp_get_current_user();

        if (!$user || empty($user->roles)) {
            return false;
        }

        return in_array(
            self::ROLE_EXECUTOR,
            (array) $user->roles,
            true
        );
    }

    /**
     * Check whether the current user has the observer role.
     *
     * @return bool
     */
    public static function is_observer(): bool
    {
        $user = wp_get_current_user();

        if (!$user || empty($user->roles)) {
            return false;
        }

        return in_array(
            self::ROLE_OBSERVER,
            (array) $user->roles,
            true
        );
    }
}