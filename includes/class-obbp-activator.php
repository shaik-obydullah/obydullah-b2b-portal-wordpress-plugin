<?php
if (!defined('ABSPATH')) {
    exit;
}

final class OBBP_Activator
{
    public static function activate()
    {
        if (!self::install_schema()) {
            wp_die(esc_html__('The B2B Portal database schema could not be installed.', 'obydullah-b2b-portal'));
        }
        OBBP_Roles::install();
        self::install_settings();
        self::ensure_cron();
        flush_rewrite_rules();
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook('obbp_expire_quotes');
        flush_rewrite_rules();
    }

    public static function ensure_cron_scheduled()
    {
        self::ensure_cron();
    }

    private static function ensure_cron()
    {
        if (!wp_next_scheduled('obbp_expire_quotes')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'obbp_expire_quotes');
        }
    }

    private static function install_settings()
    {
        $defaults = [
            'portal_page_id' => 0,
            'default_country' => 'BD',
            'default_currency' => 'BDT',
            'email_from_name' => get_bloginfo('name'),
            'email_enabled' => 1,
            'delete_data_on_uninstall' => 0,
        ];
        $settings = get_option(OBBP_OPTION_SETTINGS, []);
        update_option(OBBP_OPTION_SETTINGS, wp_parse_args(is_array($settings) ? $settings : [], $defaults));
    }

    private static function install_schema()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'obbp_';

        $companies = "CREATE TABLE {$prefix}companies (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            slug varchar(191) NOT NULL,
            email varchar(100) NOT NULL,
            phone varchar(50) NOT NULL DEFAULT '',
            website varchar(191) NOT NULL DEFAULT '',
            tax_id varchar(50) NOT NULL DEFAULT '',
            address_line1 varchar(191) NOT NULL DEFAULT '',
            address_line2 varchar(191) NOT NULL DEFAULT '',
            city varchar(100) NOT NULL DEFAULT '',
            state varchar(100) NOT NULL DEFAULT '',
            postcode varchar(20) NOT NULL DEFAULT '',
            country char(2) NOT NULL DEFAULT 'BD',
            status varchar(20) NOT NULL DEFAULT 'pending',
            status_note text NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            price_list_id bigint(20) unsigned NULL,
            credit_limit decimal(15,2) NOT NULL DEFAULT 0.00,
            payment_terms varchar(100) NOT NULL DEFAULT '',
            currency varchar(10) NOT NULL DEFAULT '',
            email_enabled tinyint(1) NOT NULL DEFAULT 1,
            approved_at datetime NULL,
            reviewed_at datetime NULL,
            reviewed_by bigint(20) unsigned NULL,
            created_at datetime NULL,
            updated_at datetime NULL,
            deleted_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY user_id (user_id),
            KEY price_list_id (price_list_id),
            KEY email (email),
            KEY deleted_at (deleted_at)
        ) ENGINE=InnoDB {$charset};";

        $contacts = "CREATE TABLE {$prefix}company_contacts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NULL,
            full_name varchar(191) NOT NULL,
            email varchar(100) NOT NULL,
            phone varchar(50) NOT NULL DEFAULT '',
            job_title varchar(100) NOT NULL DEFAULT '',
            is_primary tinyint(1) NOT NULL DEFAULT 0,
            can_approve_quotes tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            notes text NULL,
            created_at datetime NULL,
            updated_at datetime NULL,
            deleted_at datetime NULL,
            PRIMARY KEY  (id),
            KEY company_id (company_id),
            KEY user_id (user_id),
            KEY email (email),
            KEY deleted_at (deleted_at)
        ) ENGINE=InnoDB {$charset};";

        $price_lists = "CREATE TABLE {$prefix}price_lists (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            slug varchar(191) NOT NULL,
            description text NULL,
            currency varchar(10) NOT NULL DEFAULT '',
            is_default tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            valid_from date NULL,
            valid_to date NULL,
            created_at datetime NULL,
            updated_at datetime NULL,
            deleted_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY is_active (is_active),
            KEY deleted_at (deleted_at)
        ) ENGINE=InnoDB {$charset};";

        $price_list_items = "CREATE TABLE {$prefix}price_list_items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            price_list_id bigint(20) unsigned NOT NULL DEFAULT 0,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            unit_price decimal(15,4) NOT NULL DEFAULT 0.0000,
            min_qty decimal(15,4) NOT NULL DEFAULT 0.0000,
            qty_step decimal(15,4) NOT NULL DEFAULT 0.0000,
            lead_time_days int(11) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            note varchar(255) NOT NULL DEFAULT '',
            created_at datetime NULL,
            updated_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY list_product (price_list_id, product_id),
            KEY product_id (product_id)
        ) ENGINE=InnoDB {$charset};";

        $rfqs = "CREATE TABLE {$prefix}rfqs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rfq_number varchar(50) NOT NULL DEFAULT '',
            company_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            contact_id bigint(20) unsigned NULL,
            parent_rfq_id bigint(20) unsigned NULL,
            source varchar(20) NOT NULL DEFAULT 'portal',
            status varchar(20) NOT NULL DEFAULT 'draft',
            subject varchar(191) NOT NULL DEFAULT '',
            notes text NULL,
            admin_notes text NULL,
            requested_delivery_date date NULL,
            valid_until date NULL,
            currency varchar(10) NOT NULL DEFAULT '',
            item_count int(11) NOT NULL DEFAULT 0,
            submitted_at datetime NULL,
            quoted_at datetime NULL,
            closed_at datetime NULL,
            cancelled_at datetime NULL,
            cancelled_reason varchar(255) NOT NULL DEFAULT '',
            created_at datetime NULL,
            updated_at datetime NULL,
            deleted_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY rfq_number (rfq_number),
            KEY company_status (company_id, status),
            KEY status (status),
            KEY created_by_user_id (created_by_user_id),
            KEY created_at (created_at),
            KEY deleted_at (deleted_at)
        ) ENGINE=InnoDB {$charset};";

        $rfq_items = "CREATE TABLE {$prefix}rfq_items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rfq_id bigint(20) unsigned NOT NULL DEFAULT 0,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            sku varchar(100) NOT NULL DEFAULT '',
            name varchar(255) NOT NULL DEFAULT '',
            description text NULL,
            unit varchar(20) NOT NULL DEFAULT 'pcs',
            quantity decimal(15,4) NOT NULL DEFAULT 1.0000,
            target_price decimal(15,4) NULL,
            note varchar(500) NOT NULL DEFAULT '',
            line_sort int(11) NOT NULL DEFAULT 0,
            created_at datetime NULL,
            updated_at datetime NULL,
            deleted_at datetime NULL,
            PRIMARY KEY  (id),
            KEY rfq_id (rfq_id),
            KEY product_id (product_id),
            KEY deleted_at (deleted_at)
        ) ENGINE=InnoDB {$charset};";

        $quotes = "CREATE TABLE {$prefix}quotes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            quote_number varchar(50) NOT NULL DEFAULT '',
            company_id bigint(20) unsigned NOT NULL DEFAULT 0,
            rfq_id bigint(20) unsigned NULL,
            parent_quote_id bigint(20) unsigned NULL,
            revision int(11) NOT NULL DEFAULT 1,
            status varchar(20) NOT NULL DEFAULT 'draft',
            is_partial tinyint(1) NOT NULL DEFAULT 0,
            issue_date date NULL,
            valid_until date NULL,
            currency varchar(10) NOT NULL DEFAULT '',
            subtotal decimal(15,2) NOT NULL DEFAULT 0.00,
            discount_type varchar(10) NOT NULL DEFAULT '',
            discount_value decimal(15,4) NOT NULL DEFAULT 0.0000,
            discount_total decimal(15,2) NOT NULL DEFAULT 0.00,
            tax_total decimal(15,2) NOT NULL DEFAULT 0.00,
            shipping_total decimal(15,2) NOT NULL DEFAULT 0.00,
            grand_total decimal(15,2) NOT NULL DEFAULT 0.00,
            payment_terms varchar(191) NOT NULL DEFAULT '',
            delivery_terms varchar(191) NOT NULL DEFAULT '',
            notes text NULL,
            internal_notes text NULL,
            sent_at datetime NULL,
            viewed_at datetime NULL,
            accepted_at datetime NULL,
            accepted_by_user_id bigint(20) unsigned NULL,
            accepted_ip varchar(45) NOT NULL DEFAULT '',
            rejected_at datetime NULL,
            rejection_reason varchar(500) NOT NULL DEFAULT '',
            expired_at datetime NULL,
            cancelled_at datetime NULL,
            cancelled_reason varchar(255) NOT NULL DEFAULT '',
            superseded_by_id bigint(20) unsigned NULL,
            created_at datetime NULL,
            updated_at datetime NULL,
            deleted_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY quote_number (quote_number),
            KEY company_status (company_id, status),
            KEY rfq_id (rfq_id),
            KEY status (status),
            KEY valid_until (valid_until),
            KEY deleted_at (deleted_at)
        ) ENGINE=InnoDB {$charset};";

        $quote_items = "CREATE TABLE {$prefix}quote_items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
            rfq_item_id bigint(20) unsigned NULL,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            sku varchar(100) NOT NULL DEFAULT '',
            name varchar(255) NOT NULL DEFAULT '',
            description text NULL,
            unit varchar(20) NOT NULL DEFAULT 'pcs',
            quantity decimal(15,4) NOT NULL DEFAULT 1.0000,
            list_price decimal(15,4) NOT NULL DEFAULT 0.0000,
            unit_price decimal(15,4) NOT NULL DEFAULT 0.0000,
            line_discount decimal(15,4) NOT NULL DEFAULT 0.0000,
            tax_rate decimal(7,4) NOT NULL DEFAULT 0.0000,
            tax_amount decimal(15,2) NOT NULL DEFAULT 0.00,
            line_total decimal(15,2) NOT NULL DEFAULT 0.00,
            line_tax_total decimal(15,2) NOT NULL DEFAULT 0.00,
            lead_time_days int(11) NOT NULL DEFAULT 0,
            accepted_quantity decimal(15,4) NULL,
            note varchar(500) NOT NULL DEFAULT '',
            line_sort int(11) NOT NULL DEFAULT 0,
            created_at datetime NULL,
            updated_at datetime NULL,
            deleted_at datetime NULL,
            PRIMARY KEY  (id),
            KEY quote_id (quote_id),
            KEY product_id (product_id),
            KEY rfq_item_id (rfq_item_id),
            KEY deleted_at (deleted_at)
        ) ENGINE=InnoDB {$charset};";

        $purchase_orders = "CREATE TABLE {$prefix}purchase_orders (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            po_number varchar(50) NOT NULL DEFAULT '',
            company_id bigint(20) unsigned NOT NULL DEFAULT 0,
            quote_id bigint(20) unsigned NULL,
            rfq_id bigint(20) unsigned NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            payment_status varchar(20) NOT NULL DEFAULT 'unpaid',
            currency varchar(10) NOT NULL DEFAULT '',
            grand_total decimal(15,2) NOT NULL DEFAULT 0.00,
            ship_to_contact_id bigint(20) unsigned NULL,
            ship_to_name varchar(191) NOT NULL DEFAULT '',
            ship_to_address text NULL,
            customer_note text NULL,
            internal_notes text NULL,
            requested_ship_date date NULL,
            promised_ship_date date NULL,
            delivered_date date NULL,
            issued_at datetime NULL,
            issued_by bigint(20) unsigned NULL,
            acknowledged_at datetime NULL,
            dispatched_at datetime NULL,
            completed_at datetime NULL,
            cancelled_at datetime NULL,
            cancelled_reason varchar(255) NOT NULL DEFAULT '',
            created_at datetime NULL,
            updated_at datetime NULL,
            deleted_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY po_number (po_number),
            UNIQUE KEY quote_id (quote_id),
            KEY company_status (company_id, status),
            KEY status (status),
            KEY issued_at (issued_at),
            KEY deleted_at (deleted_at)
        ) ENGINE=InnoDB {$charset};";

        $purchase_order_items = "CREATE TABLE {$prefix}purchase_order_items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            purchase_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            quote_item_id bigint(20) unsigned NULL,
            rfq_item_id bigint(20) unsigned NULL,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            sku varchar(100) NOT NULL DEFAULT '',
            name varchar(255) NOT NULL DEFAULT '',
            description text NULL,
            unit varchar(20) NOT NULL DEFAULT 'pcs',
            quantity decimal(15,4) NOT NULL DEFAULT 1.0000,
            unit_price decimal(15,4) NOT NULL DEFAULT 0.0000,
            tax_rate decimal(7,4) NOT NULL DEFAULT 0.0000,
            tax_amount decimal(15,2) NOT NULL DEFAULT 0.00,
            line_total decimal(15,2) NOT NULL DEFAULT 0.00,
            line_tax_total decimal(15,2) NOT NULL DEFAULT 0.00,
            quantity_delivered decimal(15,4) NOT NULL DEFAULT 0.0000,
            lead_time_days int(11) NOT NULL DEFAULT 0,
            note varchar(500) NOT NULL DEFAULT '',
            line_sort int(11) NOT NULL DEFAULT 0,
            created_at datetime NULL,
            updated_at datetime NULL,
            deleted_at datetime NULL,
            PRIMARY KEY  (id),
            KEY purchase_order_id (purchase_order_id),
            KEY product_id (product_id),
            KEY deleted_at (deleted_at)
        ) ENGINE=InnoDB {$charset};";

        $activity_logs = "CREATE TABLE {$prefix}activity_logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_login varchar(60) NOT NULL DEFAULT '',
            actor_type varchar(20) NOT NULL DEFAULT 'system',
            object_type varchar(30) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(50) NOT NULL DEFAULT '',
            severity varchar(10) NOT NULL DEFAULT 'info',
            message text NULL,
            context longtext NULL,
            ip_address varchar(45) NOT NULL DEFAULT '',
            user_agent varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY object (object_type, object_id),
            KEY company_created (company_id, created_at),
            KEY user_id (user_id),
            KEY action (action),
            KEY created_at (created_at)
        ) ENGINE=InnoDB {$charset};";

        $schema = [
            $prefix . 'companies' => $companies,
            $prefix . 'company_contacts' => $contacts,
            $prefix . 'price_lists' => $price_lists,
            $prefix . 'price_list_items' => $price_list_items,
            $prefix . 'rfqs' => $rfqs,
            $prefix . 'rfq_items' => $rfq_items,
            $prefix . 'quotes' => $quotes,
            $prefix . 'quote_items' => $quote_items,
            $prefix . 'purchase_orders' => $purchase_orders,
            $prefix . 'purchase_order_items' => $purchase_order_items,
            $prefix . 'activity_logs' => $activity_logs,
        ];

        $errors = [];
        foreach ($schema as $table => $statement) {
            $wpdb->last_error = '';
            $result = dbDelta($statement);
            if (false === $result || $wpdb->last_error) {
                $errors[] = $table . ': ' . $wpdb->last_error;
            }
        }
        foreach (array_keys($schema) as $table) {
            $wpdb->last_error = '';
            if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
                $errors[] = $table . ': table was not created.';
            }
        }
        if ($errors) {
            return false;
        }
        return true;
    }
}
