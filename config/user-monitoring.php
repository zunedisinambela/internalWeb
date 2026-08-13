<?php

use App\Models\User;
use App\Monitoring\PageViewsOnly;

/*
|--------------------------------------------------------------------------
| binafy/laravel-user-monitoring
|--------------------------------------------------------------------------
|
| Trimmed from the package default. Three settings differ on purpose and are
| commented where they appear:
|
|   - action_monitoring is off entirely (activitylog already covers it)
|   - authentications delete_user_record_when_user_delete is false
|   - visit conditions filter out background requests
|
| Table names feed both the migrations and the queries, so renaming one after
| migrating leaves the old table orphaned and down() dropping the wrong name.
| Treat them as fixed once migrated.
|
*/

return [
    'config' => [
        /*
         * routes/user-monitoring.php is deliberately empty — it exists to
         * suppress the package's six unauthenticated routes. See that file.
         */
        'routes' => [
            'file_path' => 'routes/user-monitoring.php',
        ],

        /*
         * Only affects the package's own Blade dashboards, which are not
         * routed. Kept for completeness.
         */
        'dark_mode' => false,
    ],

    'user' => [
        'model' => User::class,
        'foreign_key' => 'user_id',
        'table' => 'users',

        /*
         * Single guard — this app has no API or separate admin guard.
         */
        'guards' => ['web'],

        'foreign_key_type' => 'id',
        'display_attribute' => 'name',
    ],

    'visit_monitoring' => [
        'table' => 'visits_monitoring',

        'turn_on' => true,

        /*
         * Livewire drives every Filament table sort, search keystroke and
         * modal, so counting its background POSTs as page views would bury
         * the real navigation. PageViewsOnly rejects them by header, which
         * survives Livewire's randomised URL prefix; except_pages below
         * cannot, because that prefix changes with the app key.
         */
        'ajax_requests' => false,

        'except_pages' => [
            'up',
        ],

        /*
         * Left at 0 on purpose — retention is not configured here. A screen
         * cannot write to a config file, so the cutoff lives in the
         * monitoring_settings table and is edited at /admin/monitoring.
         * App\Console\Commands\PruneMonitoring reads it from there.
         *
         * Keeping this at 0 also keeps the package's own
         * laravel-user-monitoring:remove-visit-monitoring-records inert: it
         * refuses to run while this is 0. Two commands pruning the same table
         * from two different cutoffs would be a mess.
         */
        'delete_days' => 0,

        /*
         * Anonymous hits are recorded with a null user_id. Failed probes at
         * /admin are exactly what this table is worth having for.
         */
        'guest_mode' => true,

        'conditions' => [
            PageViewsOnly::class,
        ],
    ],

    /*
     * Off by design. spatie/laravel-activitylog already records model changes
     * with a per-column diff, a causer and a subject, browsable at
     * /admin/activities. This package's action monitoring stores only a table
     * name, so enabling it would produce a second, poorer trail of the same
     * events. No model uses the Actionable trait.
     *
     * on_read is the one to never turn on: it hooks the `retrieved` event, so
     * a single /admin/users page writes one row per listed user.
     */
    'action_monitoring' => [
        'table' => 'actions_monitoring',

        'on_store' => false,
        'on_update' => false,
        'on_destroy' => false,
        'on_read' => false,
        'on_restore' => false,
        'on_replicate' => false,

        'use_reverse_proxy_ip' => false,
        'real_ip_header' => 'X-Forwarded-For',

        'guest_mode' => false,

        'conditions' => [],
    ],

    'authentication_monitoring' => [
        'table' => 'authentications_monitoring',

        /*
         * The package default is true, which makes user_id cascade on delete
         * and wipes an account's entire login history the moment it is removed
         * at /admin/users. False gives nullOnDelete() instead, matching the
         * visits table and keeping the trail after the account is gone.
         *
         * Only read at migration time — changing it later needs a migration.
         */
        'delete_user_record_when_user_delete' => false,

        'on_login' => true,
        'on_logout' => true,
    ],
];
