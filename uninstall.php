<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

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
$obbp_user_ids = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT user_id FROM %i WHERE meta_key = %s AND meta_value = %s', $wpdb->usermeta, '_obbp_created_by_plugin', '1'));
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
foreach ($obbp_user_ids as $obbp_user_id) {
    $obbp_user_id = absint($obbp_user_id);
    $obbp_user = $obbp_user_id ? get_userdata($obbp_user_id) : null;
    if (!$obbp_user || array_diff((array) $obbp_user->roles, ['obbp_customer'])) {
        continue;
    }
    $obbp_authored = get_posts(['author' => $obbp_user_id, 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids']);
    if ($obbp_authored) {
        continue;
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $obbp_deleted_user = wp_delete_user($obbp_user_id);
    if (is_wp_error($obbp_deleted_user) || !$obbp_deleted_user) {
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