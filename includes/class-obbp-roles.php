<?php
if (!defined('ABSPATH')) {
    exit;
}

final class OBBP_Roles
{
    public static function admin_capabilities()
    {
        return [
            'obbp_view_dashboard',
            'obbp_manage_companies',
            'obbp_manage_contacts',
            'obbp_manage_catalog',
            'obbp_manage_price_lists',
            'obbp_manage_rfqs',
            'obbp_manage_quotes',
            'obbp_manage_purchase_orders',
            'obbp_view_activity',
            'obbp_manage_settings',
            'obbp_import_csv',
            'obbp_export_csv',
            'obbp_edit_product',
            'obbp_read_product',
            'obbp_delete_product',
            'obbp_edit_products',
            'obbp_edit_others_products',
            'obbp_publish_products',
            'obbp_edit_published_products',
            'obbp_edit_private_products',
            'obbp_delete_products',
            'obbp_delete_others_products',
            'obbp_delete_published_products',
            'obbp_delete_private_products',
            'obbp_read_private_products',
            'obbp_manage_product_terms',
            'obbp_edit_product_terms',
            'obbp_delete_product_terms',
            'obbp_assign_product_terms',
            'edit_obbp_product',
            'read_obbp_product',
            'delete_obbp_product',
            'edit_obbp_products',
            'edit_others_obbp_products',
            'publish_obbp_products',
            'read_private_obbp_products',
            'delete_obbp_products',
            'delete_others_obbp_products',
            'delete_published_obbp_products',
            'delete_private_obbp_products',
        ];
    }

    public static function customer_capabilities()
    {
        return [
            'read',
            'obbp_access_portal',
            'obbp_submit_rfq',
            'obbp_view_rfqs',
            'obbp_view_quotes',
            'obbp_accept_quotes',
            'obbp_view_orders',
            'obbp_reorder',
        ];
    }

    public static function install()
    {
        $manager = ['read' => true];
        foreach (self::admin_capabilities() as $capability) {
            $manager[$capability] = true;
        }
        self::upsert_role('obbp_manager', __('B2B Manager', 'obydullah-b2b-portal'), $manager);

        $sales = [
            'read' => true,
            'obbp_view_dashboard' => true,
            'obbp_manage_catalog' => true,
            'obbp_manage_price_lists' => true,
            'obbp_manage_rfqs' => true,
            'obbp_manage_quotes' => true,
            'obbp_manage_purchase_orders' => true,
            'obbp_view_activity' => true,
            'edit_obbp_product' => true,
            'read_obbp_product' => true,
            'delete_obbp_product' => true,
            'edit_obbp_products' => true,
            'edit_others_obbp_products' => true,
            'publish_obbp_products' => true,
            'read_private_obbp_products' => true,
            'delete_obbp_products' => true,
            'delete_others_obbp_products' => true,
            'delete_published_obbp_products' => true,
            'delete_private_obbp_products' => true,
        ];
        self::upsert_role('obbp_sales', __('B2B Sales', 'obydullah-b2b-portal'), $sales);
        self::upsert_role(
            'obbp_customer',
            __('B2B Customer', 'obydullah-b2b-portal'),
            array_combine(self::customer_capabilities(), array_fill(0, count(self::customer_capabilities()), true))
        );

        $administrator = get_role('administrator');
        if ($administrator) {
            foreach (self::admin_capabilities() as $capability) {
                $administrator->add_cap($capability);
            }
        }
    }

    private static function upsert_role($role_name, $display_name, $capabilities)
    {
        $role = get_role($role_name);
        if (!$role) {
            add_role($role_name, $display_name, $capabilities);
            return;
        }
        foreach ($capabilities as $capability => $enabled) {
            if ($enabled) {
                $role->add_cap($capability);
            }
        }
    }

    public static function grant_admin_capabilities()
    {
        self::install();
    }
}
