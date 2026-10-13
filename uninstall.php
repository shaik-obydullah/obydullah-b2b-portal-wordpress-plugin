<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Uninstall only touches the plugin's own obbp_* tables and its roles, options
// and capabilities. Values are bound via $wpdb->prepare(); the temporary schema
// change (DROP TABLE) is the deliberate purpose of this script.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

$obbp_option_settings = defined('OBBP_OPTION_SETTINGS') ? OBBP_OPTION_SETTINGS : 'obbp_settings';
$obbp_settings = get_option($obbp_option_settings, []);
wp_clear_scheduled_hook('obbp_expire_quotes');
foreach (['obbp_manager', 'obbp_sales', 'obbp_customer'] as $obbp_role_name) {
    if (get_role($obbp_role_name)) {
        remove_role($obbp_role_name);
    }
}
$obbp_administrator = get_role('administrator');
if ($obbp_administrator) {
    foreach (array_keys($obbp_administrator->capabilities) as $obbp_capability) {
        if (str_starts_with($obbp_capability, 'obbp_')) {
            $obbp_administrator->remove_cap($obbp_capability);
        }
    }
}
if (empty($obbp_settings['delete_data_on_uninstall'])) {
    return;
}

global $wpdb;
$obbp_prefix = $wpdb->prefix . 'obbp_';
$obbp_tables = [
    $obbp_prefix . 'activity_logs',
    $obbp_prefix . 'purchase_order_items',
    $obbp_prefix . 'purchase_orders',
    $obbp_prefix . 'quote_items',
    $obbp_prefix . 'quotes',
    $obbp_prefix . 'rfq_items',
    $obbp_prefix . 'rfqs',
    $obbp_prefix . 'price_list_items',
    $obbp_prefix . 'price_lists',
    $obbp_prefix . 'company_contacts',
    $obbp_prefix . 'companies',
];
$obbp_product_ids = $wpdb->get_col($wpdb->prepare('SELECT ID FROM %i WHERE post_type = %s AND post_status != %s', $wpdb->posts, 'obbp_product', 'trash'));
$obbp_cleanup_failed = false;
foreach ($obbp_tables as $obbp_table) {
    if (false === $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $obbp_table))) {
        $obbp_cleanup_failed = true;
    }
}
foreach ($obbp_product_ids as $obbp_product_id) {
    $obbp_deleted = wp_delete_post(absint($obbp_product_id), true);
    if (!$obbp_deleted) {
        $obbp_cleanup_failed = true;
    }
}
foreach (['_obbp_created_by_plugin', '_obbp_company_id'] as $obbp_meta_key) {
    if (false === $wpdb->delete($wpdb->usermeta, ['meta_key' => $obbp_meta_key], ['%s'])) {
        $obbp_cleanup_failed = true;
    }
}
if ($obbp_cleanup_failed) {
    return;
}
delete_option($obbp_option_settings);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange