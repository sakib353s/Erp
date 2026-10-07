<?php

/**
 * BD ERP — foundation configuration.
 *
 * These are environment/config-level defaults only. Runtime-configurable
 * business behaviour lives in the `settings` table (see SettingService),
 * which falls back to these values when no row exists. No fake business
 * data, no hard-coded workflow routing, no hard-coded tax rates.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Single-company invariant (Rule 1/2)
    |--------------------------------------------------------------------------
    | An operational ERP instance always represents exactly ONE company with
    | many branches. Creating a second company inside a tenant is prohibited
    | and is enforced in CompanyService + a DB unique constraint.
    */
    'company' => [
        'singleton' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | First-boot setup (spec §49)
    |--------------------------------------------------------------------------
    | The first Super Admin can only be created through the explicit setup
    | process, which requires a one-time token produced by
    | `php artisan erp:setup-token`. No default password ever exists.
    */
    'setup' => [
        'token_ttl_minutes' => (int) env('ERP_SETUP_TOKEN_TTL', 60),
        'max_token_attempts' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security foundation (spec section C)
    |--------------------------------------------------------------------------
    | All values are overridable at runtime through the `security` settings
    | group (SettingService); these are the defaults.
    */
    'security' => [

        'password' => [
            'min_length' => (int) env('ERP_PASSWORD_MIN_LENGTH', 10),
            'max_length' => 200,
            'require_upper' => env('ERP_PASSWORD_REQUIRE_UPPER', true),
            'require_lower' => env('ERP_PASSWORD_REQUIRE_LOWER', true),
            'require_digit' => env('ERP_PASSWORD_REQUIRE_DIGIT', true),
            'require_special' => env('ERP_PASSWORD_REQUIRE_SPECIAL', true),
            'max_age_days' => (int) env('ERP_PASSWORD_MAX_AGE_DAYS', 90),
            'history_depth' => (int) env('ERP_PASSWORD_HISTORY_DEPTH', 5),
            'blocked' => [
                'password', 'passw0rd', 'password1', '1234567890', 'qwertyuiop',
                'admin1234', 'letmein123', 'welcome123', 'iloveyou1', 'erpadmin123',
            ],
        ],

        'lockout' => [
            'max_failed_attempts' => (int) env('ERP_LOCKOUT_MAX_ATTEMPTS', 5),
            'lockout_minutes' => (int) env('ERP_LOCKOUT_MINUTES', 15),
            'decay_seconds' => (int) env('ERP_LOGIN_THROTTLE_DECAY', 60),
            'max_throttle_hits' => (int) env('ERP_LOGIN_THROTTLE_MAX', 20),
        ],

        'session' => [
            'idle_timeout_minutes' => (int) env('ERP_SESSION_IDLE_MINUTES', 30),
            'max_concurrent' => (int) env('ERP_SESSION_MAX_CONCURRENT', 3),
        ],

        'headers' => [
            'x_content_type_options' => 'nosniff',
            'x_frame_options' => 'SAMEORIGIN',
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'permissions_policy' => 'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
            'cross_origin_opener_policy' => 'same-origin',
            'hsts' => (bool) env('ERP_HSTS', false), // enable behind HTTPS
            'hsts_max_age' => 31536000,
        ],

        'suspicious_login' => [
            'known_days' => 90,
            'alert_on_new_device' => (bool) env('ERP_ALERT_NEW_DEVICE', true),
        ],

        /** Keys never written into audit rows / logs (Rule 16 + spec §C). */
        'redaction_keys' => [
            'password', 'password_confirmation', 'current_password', 'new_password',
            'token', 'setup_token', 'api_key', 'apikey', 'secret', 'client_secret',
            'authorization', 'cookie', 'set-cookie', 'session', 'session_key',
            'pin', 'otp', 'private_key', 'app_key', 'credential', 'credentials',
            'access_token', 'refresh_token', 'remember_token', 'signature',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File / upload foundation (spec section K)
    |--------------------------------------------------------------------------
    | Uploads are validated by real MIME sniffing (fileinfo), never by the
    | client-declared Content-Type. Filenames are always regenerated.
    */
    'upload' => [
        'max_bytes' => (int) env('ERP_UPLOAD_MAX_BYTES', 10 * 1024 * 1024),
        'image_max_bytes' => (int) env('ERP_UPLOAD_IMAGE_MAX_BYTES', 4 * 1024 * 1024),
        'allowed_extensions' => [
            'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'csv', 'txt',
            'doc', 'docx', 'xls', 'xlsx', 'zip',
        ],
        // extension => list of acceptable finfo MIME prefixes/substrings
        'allowed_mimes' => [
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'csv' => ['text/csv', 'application/csv', 'text/plain', 'application/vnd.ms-excel', 'text/x-csv'],
            'txt' => ['text/plain'],
            'doc' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            'xls' => ['application/vnd.ms-excel', 'application/octet-stream', 'application/xls'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
        ],
        'image_extensions' => ['png', 'jpg', 'jpeg', 'gif', 'webp'],
        'generate_webp' => true,
        'webp_quality' => 85,
        'path_pattern' => 'Y/m', // storage path segments for generated files
    ],

    /*
    |--------------------------------------------------------------------------
    | Document numbering (spec section L; NOT hard-coded in controllers)
    |--------------------------------------------------------------------------
    | Pattern tokens: {PREFIX} {BRANCH} {YYYY} {YY} {MM} {SEQ} {DOC}
    */
    'numbering' => [
        'default_pattern' => '{PREFIX}/{YYYY}/{SEQ}',
        'default_padding' => 5,
        'default_reset_period' => 'none', // none|yearly|monthly
    ],

    /*
    |--------------------------------------------------------------------------
    | Generic workflow / approval engine (spec section G)
    |--------------------------------------------------------------------------
    */
    'workflow' => [
        'default_step_due_hours' => (int) env('ERP_WORKFLOW_STEP_DUE_HOURS', 48),
        'escalation_check_minutes' => 15,
        'block_self_approval_default' => true,
        'approval_modes' => ['sequential', 'parallel'],
        'request_statuses' => ['pending', 'approved', 'rejected', 'returned', 'cancelled'],
        'step_statuses' => ['pending', 'approved', 'rejected', 'returned', 'skipped', 'cancelled'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing (02-109 bulk price update gate)
    |--------------------------------------------------------------------------
    */
    'pricing' => [
        // Largest absolute % change allowed before a bulk update must go
        // through workflow approval (0 = never gate).
        'bulk_update_threshold_pct' => (float) env('ERP_PRICE_BULK_UPDATE_THRESHOLD_PCT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbox / events (spec section I; decision D13)
    |--------------------------------------------------------------------------
    */
    'outbox' => [
        'max_attempts' => (int) env('ERP_OUTBOX_MAX_ATTEMPTS', 5),
        // backoff in seconds, indexed by (attempts - 1); last value repeats
        'backoff_seconds' => [60, 300, 1800, 7200, 21600],
        'prune_after_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency (spec §9.3 / decision D19)
    */
    'idempotency' => [
        'ttl_hours' => (int) env('ERP_IDEMPOTENCY_TTL_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit (spec section H; decision D14)
    |--------------------------------------------------------------------------
    */
    'audit' => [
        'hash_algo' => 'sha256',
        'chain_enabled' => true,
        'max_snapshot_kb' => 64,
        'actions' => [
            // taxonomy used by AuditRecorder::record(); modules extend it
            'auth.login', 'auth.logout', 'auth.login_failed', 'auth.lockout',
            'auth.password_changed', 'auth.suspicious_login',
            'record.create', 'record.update', 'record.delete',
            'approval.submit', 'approval.approve', 'approval.reject',
            'approval.return', 'approval.cancel', 'approval.comment',
            'approval.delegate', 'approval.escalate',
            'document.print', 'document.download', 'document.upload', 'document.export',
            'role.create', 'role.update', 'role.delete', 'role.permissions_synced',
            'role.assigned', 'permission.denied',
            'branch.switch', 'branch.create', 'branch.update',
            'config.update', 'config.protected_denied',
            'security.alert', 'security.session_expired', 'security.sessions_pruned',
            'workflow.definition_toggled', 'menu.status_changed',
            'setting.update', 'entitlement.changed',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications (spec section J)
    |--------------------------------------------------------------------------
    | External channels are optional adapters (Rule 18): their defaults are
    | disabled until a real provider is configured — never fake "sent".
    */
    'notifications' => [
        'poll_seconds' => (int) env('ERP_NOTIFICATION_POLL', 60),
        'channels' => ['inapp', 'email', 'sms', 'whatsapp'],
        'channel_defaults' => [
            'inapp' => true,
            'email' => false,
            'sms' => false,
            'whatsapp' => false,
        ],
        'priorities' => ['low', 'normal', 'high', 'critical'],
        'security_recipients_permission' => 'security.alerts.view',
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation registry (spec section F + §47 catalog)
    |--------------------------------------------------------------------------
    | The §47 catalog is the AUTHORITY for coverage (what pages exist and who
    | may open them). It is deliberately NOT a sidebar layout: the supplied
    | tree is 1,022 lines of spec prose whose verb-first leaves ("Bulk Print
    | Invoice", "Session Opening") are ACTIONS on a page, not destinations.
    | Rendering it verbatim produced §18.3's forbidden "wall-of-text sidebar"
    | in which dozens of links opened the same screen.
    |
    | Curation policy (all data-driven, nothing hard-coded in Blade):
    |   · location=sidebar  → a DESTINATION (renders in the nav)
    |   · location=action   → an ACTION on its parent page (never a nav link;
    |                          it keeps its permission row and reaches the user
    |                          through the page toolbar and the ⌘K palette)
    |   · sections          → job-to-be-done grouping shown in the sidebar
    |   · max_depth         → hard cap on nav nesting (deeper pages are served
    |                          by the palette, the page tabs and the module rail)
    |   · dedupe_by_path    → one canonical link per screen; query variants
    |                          (`?status=pending`) become saved views/tabs
    */
    'navigation' => [
        'catalog_file' => database_path('catalog/menu_tree.txt'),
        'widgets_file' => database_path('catalog/widgets.txt'),
        'locations' => ['sidebar', 'header', 'utility', 'action'],
        'statuses' => ['active', 'planned'], // planned entries never render

        /* Sidebar curation (v2) */
        'max_depth' => 2,               // section → module → page (never deeper)
        'max_children' => 8,            // per module group; the rest live in ⌘K
        'dedupe_by_path' => true,       // one canonical link per screen
        'collapse_query_variants' => true,
        'show_favorites' => true,
        'show_recents' => true,

        /*
         | Business-domain sections, in display order. Each section carries a
         | vibrant hue (1-10 → --c1…--c10 in app.css) so the rail is scannable
         | by domain: Sales & CRM, Inventory, Accounts, People, …
         */
        'sections' => [
            'work' => ['label' => 'My work', 'icon' => 'bi-lightning-charge', 'hue' => 8],
            'crm' => ['label' => 'Sales & CRM', 'icon' => 'bi-cart3', 'hue' => 1],
            'stock' => ['label' => 'Inventory & warehouse', 'icon' => 'bi-box-seam', 'hue' => 2],
            'finance' => ['label' => 'Accounts & finance', 'icon' => 'bi-cash-stack', 'hue' => 5],
            'hr' => ['label' => 'People & payroll', 'icon' => 'bi-people', 'hue' => 6],
            'growth' => ['label' => 'Marketing & growth', 'icon' => 'bi-megaphone', 'hue' => 4],
            'insight' => ['label' => 'Reports & insight', 'icon' => 'bi-graph-up-arrow', 'hue' => 3],
            'govern' => ['label' => 'Governance', 'icon' => 'bi-shield-check', 'hue' => 7],
            'configure' => ['label' => 'Settings & masters', 'icon' => 'bi-sliders', 'hue' => 8],
        ],

        /* module code → section code (unknown modules fall back to 'govern'). */
        'module_sections' => [
            'dashboard' => 'work',

            // Sales & CRM — the commercial front office (02, 05, 07) + counter
            'sales' => 'crm',
            'pos' => 'crm',
            'customers' => 'crm',
            'returns' => 'crm',

            // Inventory & warehouse — buying, stock, suppliers (03, 04, 06)
            'purchase' => 'stock',
            'inventory' => 'stock',
            'suppliers' => 'stock',

            // Accounts & finance — cash, bank, ledger, VAT (08, 09)
            'cash_bank' => 'finance',
            'accounting' => 'finance',

            // People & payroll — HRM (10)
            'employee' => 'hr',

            // Marketing & growth (11)
            'marketing' => 'growth',

            // Reports & insight (13)
            'reports' => 'insight',

            // Governance — approvals, audit, workflows, business management (12)
            'business_management' => 'govern',

            // Settings & masters (14, 15)
            'masters' => 'configure',
            'settings' => 'configure',
        ],

        /* Display order of modules inside a section. */
        'module_order' => [
            'dashboard', 'sales', 'pos', 'customers', 'returns',
            'purchase', 'inventory', 'suppliers',
            'cash_bank', 'accounting',
            'employee',
            'reports',
            'marketing', 'business_management',
            'masters', 'settings',
        ],

        /* Entries promoted into the "My work" section regardless of module. */
        'work_routes' => [
            '/app/dashboard',
            '/app/approvals',
            '/app/notifications',
            '/app/tasks',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard (spec §46 — exactly 25 widget containers, decision D22)
    */
    'dashboard' => [
        'expected_widget_containers' => 25,
        'widget_cache_ttl' => 60, // used from Phase N onward
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature entitlements (mirrored by the platform control plane — C3)
    */
    'features' => [
        'keys' => [
            'dashboard', 'sales', 'purchase', 'inventory', 'customers', 'suppliers',
            'returns', 'cash_bank', 'accounting', 'employee', 'marketing',
            'business_management', 'reports', 'masters', 'settings',
            'pos', 'courier', 'sms', 'email', 'whatsapp', 'warranty',
            'technician_portal', 'supplier_portal', 'multi_branch',
        ],
        'default_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings groups — metadata driving the settings UI forms.
    | Values persist in the `settings` table; this file only defines which
    | keys exist, their type and labels (structural, not fake data).
    */
    'settings' => [
        'protected_keys' => [
            'instance.slug', 'instance.provisioned_at', 'setup.token_hash',
            'company.singleton',
        ],

        'groups' => [
            'general' => [
                'label' => 'General Settings',
                'description' => 'Company-wide display and formatting defaults.',
                'fields' => [
                    'date_format' => [
                        'label' => 'Date format', 'type' => 'select',
                        'options' => ['d/m/Y' => 'd/m/Y (31/12/2026)', 'm/d/Y' => 'm/d/Y (12/31/2026)', 'Y-m-d' => 'Y-m-d (2026-12-31)'],
                        'default' => 'd/m/Y',
                    ],
                    'time_format' => [
                        'label' => 'Time format', 'type' => 'select',
                        'options' => ['H:i' => '24-hour (14:30)', 'h:i A' => '12-hour (2:30 pm)'],
                        'default' => 'H:i',
                    ],
                    'decimal_places' => [
                        'label' => 'Decimal places for amounts', 'type' => 'number',
                        'default' => 2, 'min' => 0, 'max' => 4,
                    ],
                    'thousands_separator' => [
                        'label' => 'Thousands separator', 'type' => 'select',
                        'options' => [',' => '1,234,567', ' ' => '1 234 567', 'none' => '1234567'],
                        'default' => ',',
                    ],
                    'default_landing' => [
                        'label' => 'Default landing page after login', 'type' => 'select',
                        'options' => ['/app/dashboard' => 'Dashboard', '/app/approvals' => 'Approval Inbox'],
                        'default' => '/app/dashboard',
                    ],
                ],
            ],

            'localization' => [
                'label' => 'Bengali / Localization Settings',
                'description' => 'Language toggle, Bengali numerals and amount-in-words behaviour.',
                'fields' => [
                    'default_locale' => [
                        'label' => 'Default language', 'type' => 'select',
                        'options' => ['en' => 'English', 'bn' => 'বাংলা'], 'default' => 'en',
                    ],
                    'bengali_numerals' => [
                        'label' => 'Show Bengali numerals in print', 'type' => 'boolean', 'default' => false,
                    ],
                    'amount_words_bn' => [
                        'label' => 'Amount in words in Bengali on documents', 'type' => 'boolean', 'default' => true,
                    ],
                    'lakh_crore_format' => [
                        'label' => 'Use Lakh / Crore grouping (৳1,25,000)', 'type' => 'boolean', 'default' => true,
                    ],
                ],
            ],

            'security' => [
                'label' => 'Security Settings',
                'description' => 'Password policy, lockout policy and session policy.',
                'fields' => [
                    'password_min_length' => ['label' => 'Minimum password length', 'type' => 'number', 'default' => 10, 'min' => 8, 'max' => 64],
                    'password_require_upper' => ['label' => 'Require uppercase letter', 'type' => 'boolean', 'default' => true],
                    'password_require_lower' => ['label' => 'Require lowercase letter', 'type' => 'boolean', 'default' => true],
                    'password_require_digit' => ['label' => 'Require digit', 'type' => 'boolean', 'default' => true],
                    'password_require_special' => ['label' => 'Require special character', 'type' => 'boolean', 'default' => true],
                    'password_max_age_days' => ['label' => 'Password expires after (days, 0 = never)', 'type' => 'number', 'default' => 90, 'min' => 0, 'max' => 365],
                    'lockout_max_attempts' => ['label' => 'Lock account after failed logins', 'type' => 'number', 'default' => 5, 'min' => 3, 'max' => 50],
                    'lockout_minutes' => ['label' => 'Lockout duration (minutes)', 'type' => 'number', 'default' => 15, 'min' => 1, 'max' => 1440],
                    'session_idle_minutes' => ['label' => 'Session idle timeout (minutes)', 'type' => 'number', 'default' => 30, 'min' => 5, 'max' => 720],
                    'session_max_concurrent' => ['label' => 'Max concurrent sessions per user', 'type' => 'number', 'default' => 3, 'min' => 1, 'max' => 20],
                ],
            ],

            'notifications' => [
                'label' => 'Notification Settings',
                'description' => 'Channel defaults. External channels stay disabled until a real provider is configured.',
                'fields' => [
                    'channel_email_enabled' => ['label' => 'Enable email notifications (requires configured SMTP)', 'type' => 'boolean', 'default' => false],
                    'channel_sms_enabled' => ['label' => 'Enable SMS notifications (requires configured SMS provider)', 'type' => 'boolean', 'default' => false],
                    'channel_whatsapp_enabled' => ['label' => 'Enable WhatsApp notifications (requires configured provider)', 'type' => 'boolean', 'default' => false],
                    'poll_seconds' => ['label' => 'Notification poll interval (seconds)', 'type' => 'number', 'default' => 60, 'min' => 15, 'max' => 600],
                    'security_alerts_enabled' => ['label' => 'Raise in-app security alerts', 'type' => 'boolean', 'default' => true],
                ],
            ],

            'workflow' => [
                'label' => 'Workflow & Approval Settings',
                'description' => 'Defaults for the generic database-driven approval engine.',
                'fields' => [
                    'default_step_due_hours' => ['label' => 'Approval step SLA (hours)', 'type' => 'number', 'default' => 48, 'min' => 1, 'max' => 720],
                    'escalation_hours' => ['label' => 'Escalate overdue steps after (hours, 0 = off)', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 720],
                    'block_self_approval' => ['label' => 'Prevent requester from approving own request', 'type' => 'boolean', 'default' => true],
                ],
            ],

            'numbering' => [
                'label' => 'Document Numbering',
                'description' => 'Default numbering pattern applied to new numbering rules.',
                'fields' => [
                    'default_pattern' => [
                        'label' => 'Default pattern', 'type' => 'select',
                        'options' => [
                            '{PREFIX}/{YYYY}/{SEQ}' => 'INV/2026/00042',
                            '{PREFIX}/{YYMM}/{SEQ}' => 'INV/2609/00042',
                            '{BRANCH}/{PREFIX}/{YYYY}/{SEQ}' => 'DHK/INV/2026/00042',
                        ],
                        'default' => '{PREFIX}/{YYYY}/{SEQ}',
                    ],
                    'default_padding' => ['label' => 'Sequence padding (digits)', 'type' => 'number', 'default' => 5, 'min' => 3, 'max' => 10],
                    'default_reset_period' => [
                        'label' => 'Sequence resets', 'type' => 'select',
                        'options' => ['none' => 'Never', 'yearly' => 'Every year', 'monthly' => 'Every month'],
                        'default' => 'none',
                    ],
                ],
            ],

            'dashboard' => [
                'label' => 'Dashboard Settings',
                'description' => 'Widget cache and layout behaviour.',
                'fields' => [
                    'widget_cache_ttl' => ['label' => 'Widget cache TTL (seconds)', 'type' => 'number', 'default' => 60, 'min' => 0, 'max' => 3600],
                    'show_onboarding' => ['label' => 'Show onboarding checklist until setup is complete', 'type' => 'boolean', 'default' => true],
                ],
            ],

            'audit' => [
                'label' => 'Audit Log Retention',
                'description' => 'Retention policy for the tamper-evident audit trail.',
                'fields' => [
                    'retention_days' => ['label' => 'Keep audit events online for (days)', 'type' => 'number', 'default' => 365, 'min' => 30, 'max' => 3650],
                    'chain_verify_reminder_hours' => ['label' => 'Chain verification reminder (hours, 0 = off)', 'type' => 'number', 'default' => 24, 'min' => 0, 'max' => 720],
                    'allow_export' => ['label' => 'Allow audit export (permission-gated)', 'type' => 'boolean', 'default' => true],
                ],
            ],

            'pos' => [
                'label' => 'POS Settings',
                'description' => 'Receipt paper width, footer text, cash rounding and offline sync for the counter.',
                'fields' => [
                    'paper_width' => [
                        'label' => 'Receipt paper width',
                        'type' => 'select',
                        'options' => ['80' => '80 mm (standard)', '58' => '58 mm (narrow)'],
                        'default' => '80',
                    ],
                    'receipt_footer' => [
                        'label' => 'Receipt footer text',
                        'type' => 'text',
                        'max' => 500,
                        'default' => '',
                        'help' => 'Printed at the bottom of every POS receipt (max 500 characters).',
                    ],
                    'rounding_increment' => [
                        'label' => 'Cash rounding increment (BDT)',
                        'type' => 'select',
                        'options' => [
                            '0.01' => '0.01 — off (exact paisa)',
                            '0.05' => '0.05',
                            '0.10' => '0.10',
                            '0.50' => '0.50',
                            '1.00' => '1.00',
                        ],
                        'default' => '0.01',
                        'help' => 'Above 0.01 the POS grand total rounds half-up to the increment; the difference is recorded in the invoice rounding column.',
                    ],
                    'offline_enabled' => [
                        'label' => 'Allow offline POS sync',
                        'type' => 'boolean',
                        'default' => true,
                        'help' => 'When off, POST /pos/sync refuses every batch with an honest reason instead of committing offline sales.',
                    ],
                ],
            ],

            /*
             * Appearance — the §18.1 "one configurable accent" contract.
             * Every preset in resources/css/app.css is a non-purple,
             * WCAG-AA enterprise accent; the operator picks one company-wide
             * and each user may still switch light/dark + row density locally.
             */
            'inventory' => [
                'label' => 'Inventory Settings',
                'description' => 'Thresholds the stock reports and alerts judge against.',
                'fields' => [
                    'adjustment_approval_above' => [
                        'label' => 'Adjustments above this value need approval',
                        'type' => 'number',
                        'default' => 0,
                        'min' => 0,
                        'max' => 100000000,
                        'help' => 'A stock adjustment worth at least this much is stored as waiting for approval and moves no stock until a second person decides it. 0 posts every adjustment immediately.',
                    ],
                    'transfer_approval_above' => [
                        'label' => 'Transfers above this value need approval',
                        'type' => 'number',
                        'default' => 0,
                        'min' => 0,
                        'max' => 100000000,
                        'help' => 'A stock transfer worth at least this much waits for approval and cannot be dispatched until a second person clears it. 0 dispatches every transfer as it always did.',
                    ],
                    'default_cost_method' => [
                        'label' => 'Default cost method for a new product',
                        'type' => 'select',
                        'options' => [
                            'fifo' => 'FIFO — first in, first out',
                            'lifo' => 'LIFO — last in, first out',
                            'wac' => 'Weighted average',
                            'standard' => 'Standard cost',
                        ],
                        'default' => 'wac',
                        'help' => 'Preselects the valuation method on the add-product form. It is only a default: the form still decides per product, and changing a method never rewrites a layer that was already posted.',
                    ],
                    'default_is_stocked' => [
                        'label' => 'New products are stock-managed by default',
                        'type' => 'boolean',
                        'default' => true,
                        'help' => 'Turn this off if you mostly sell services through the catalogue; the product form still lets you override it per product.',
                    ],
                    'default_track_batch' => [
                        'label' => 'New products track batches by default',
                        'type' => 'boolean',
                        'default' => false,
                        'help' => 'For a pharmacy or a food business this is usually on: a batch-tracked product must name its batch on every receipt, so its expiry date can be watched.',
                    ],
                    'sku_from_code' => [
                        'label' => 'Leave SKU blank to use the product code',
                        'type' => 'boolean',
                        'default' => false,
                        'help' => 'When on, a product saved without a SKU is labelled with its code instead. The SKU is what the ledger shows, so a short code makes a tidy stock ledger; when off, the form insists on a SKU of its own.',
                    ],
                    'fefo_picking' => [
                        'label' => 'Issue batch-tracked stock expiry-first (FEFO)',
                        'type' => 'boolean',
                        'default' => true,
                        'help' => 'A batch-tracked product is consumed from the batch with the earliest expiry date, not simply the oldest receipt. Turning this off falls back to the valuation method (FIFO/LIFO) for those products too.',
                    ],
                    'expiry_alert_days' => [
                        'label' => 'Warn about a batch this many days before it expires',
                        'type' => 'number',
                        'default' => 30,
                        'min' => 1,
                        'max' => 3650,
                        'help' => 'A batch inside this window is listed on the expiry desk and raises an expiry alert; one already past its date is listed as expired whatever this says.',
                    ],
                    'dead_stock_days' => [
                        'label' => 'Dead stock after (days without movement)',
                        'type' => 'number',
                        'default' => 90,
                        'min' => 1,
                        'max' => 3650,
                        'help' => 'Days since the last movement before a product with stock on hand is reported as dead stock.',
                    ],
                ],
            ],

            'appearance' => [
                'label' => 'Appearance',
                'description' => 'Workspace identity and default reading density.',
                'fields' => [
                    'accent' => [
                        'label' => 'Accent colour',
                        'type' => 'select',
                        'options' => [
                            'emerald' => 'Vibrant emerald (default)',
                            'azure' => 'Vibrant azure',
                            'tangerine' => 'Vibrant tangerine',
                            'graphite' => 'Graphite (neutral)',
                        ],
                        'default' => 'emerald',
                        'help' => 'Used for primary actions, active states and the brand mark. Every preset is an RGB-vibrant hue; indigo, violet and pink are not offered — §18.1 forbids a purple identity.',
                    ],
                    'density' => [
                        'label' => 'Default row density',
                        'type' => 'select',
                        'options' => ['comfortable' => 'Comfortable', 'compact' => 'Compact (data entry)'],
                        'default' => 'comfortable',
                    ],
                    'theme' => [
                        'label' => 'Default theme',
                        'type' => 'select',
                        'options' => ['light' => 'Light (white-first)', 'dark' => 'Dark'],
                        'default' => 'light',
                    ],
                    'sidebar_rail' => [
                        'label' => 'Sidebar starts collapsed',
                        'type' => 'boolean',
                        'default' => false,
                        'help' => 'Users can still pin their own choice; this only sets the first-run state.',
                    ],
                ],
            ],
        ],
    ],
];
