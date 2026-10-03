<?php
/**
 * Plugin Name: Obydullah B2B Portal
 * Description: Customer-facing B2B portal, companies approve, customers request quotes, and your team quotes and issues purchase orders.
 * Version: 1.0.0
 * Author: Shaik Obydullah
 * Author URI: https://obydullah.com
 * Text Domain: obydullah-b2b-portal
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * 
 */
if (!defined('ABSPATH')) {
    exit;
}

define('OBBP_VERSION', '1.0.0');
define('OBBP_FILE', __FILE__);
define('OBBP_PATH', plugin_dir_path(__FILE__));
define('OBBP_URL', plugin_dir_url(__FILE__));
define('OBBP_BASENAME', plugin_basename(__FILE__));
define('OBBP_NONCE_ADMIN', 'obbp_admin_action');
define('OBBP_NONCE_PORTAL', 'obbp_portal_action');
define('OBBP_OPTION_SETTINGS', 'obbp_settings');

require_once OBBP_PATH . 'includes/class-obbp-plugin.php';
require_once OBBP_PATH . 'includes/class-obbp-activator.php';
require_once OBBP_PATH . 'includes/class-obbp-roles.php';
require_once OBBP_PATH . 'includes/class-obbp-repository.php';
require_once OBBP_PATH . 'includes/class-obbp-catalog.php';
require_once OBBP_PATH . 'includes/class-obbp-workflow.php';
require_once OBBP_PATH . 'includes/class-obbp-mailer.php';
require_once OBBP_PATH . 'includes/class-obbp-csv.php';
require_once OBBP_PATH . 'includes/class-obbp-admin.php';
require_once OBBP_PATH . 'includes/class-obbp-portal.php';

register_activation_hook(OBBP_FILE, ['OBBP_Activator', 'activate']);
register_deactivation_hook(OBBP_FILE, ['OBBP_Activator', 'deactivate']);

add_action('plugins_loaded', ['OBBP_Plugin', 'boot']);
add_action('init', ['OBBP_Activator', 'ensure_cron_scheduled']);