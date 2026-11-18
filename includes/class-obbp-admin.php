<?php
if (!defined('ABSPATH')) {
    exit;
}

// Direct DB access is intentional: these queries run against the plugin's own
// obbp_* tables, all values are bound via $wpdb->prepare(), and hot paths are
// served through the OBBP_Repository cache layer.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

final class OBBP_Admin
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'menu']);
        add_filter('parent_file', [$this, 'highlight_catalog_parent']);
        add_filter('submenu_file', [$this, 'highlight_catalog_submenu']);
        add_action('admin_post_obbp_action', [$this, 'handle_post']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_body_class', [$this, 'body_class']);
        add_action('admin_notices', [$this, 'notice']);
    }

    public function menu()
    {
        add_menu_page(__('B2B Portal', 'obydullah-b2b-portal'), __('B2B Portal', 'obydullah-b2b-portal'), 'obbp_view_dashboard', 'obbp-dashboard', [$this, 'dashboard'], 'dashicons-store', 75);
        add_submenu_page('obbp-dashboard', __('Dashboard', 'obydullah-b2b-portal'), __('Dashboard', 'obydullah-b2b-portal'), 'obbp_view_dashboard', 'obbp-dashboard', [$this, 'dashboard']);
        add_submenu_page('obbp-dashboard', __('Companies', 'obydullah-b2b-portal'), __('Companies', 'obydullah-b2b-portal'), 'obbp_manage_companies', 'obbp-companies', [$this, 'companies']);
        add_submenu_page('obbp-dashboard', __('Catalog', 'obydullah-b2b-portal'), __('Catalog', 'obydullah-b2b-portal'), 'obbp_manage_catalog', 'edit.php?post_type=obbp_product');
        add_submenu_page('obbp-dashboard', __('Product Categories', 'obydullah-b2b-portal'), __('Product Categories', 'obydullah-b2b-portal'), 'obbp_manage_product_terms', 'edit-tags.php?taxonomy=obbp_product_cat&post_type=obbp_product');
        add_submenu_page('obbp-dashboard', __('Product Tags', 'obydullah-b2b-portal'), __('Product Tags', 'obydullah-b2b-portal'), 'obbp_manage_product_terms', 'edit-tags.php?taxonomy=obbp_product_tag&post_type=obbp_product');
        add_submenu_page('obbp-dashboard', __('Price Lists', 'obydullah-b2b-portal'), __('Price Lists', 'obydullah-b2b-portal'), 'obbp_manage_price_lists', 'obbp-price-lists', [$this, 'price_lists']);
        add_submenu_page('obbp-dashboard', __('RFQs', 'obydullah-b2b-portal'), __('RFQs', 'obydullah-b2b-portal'), 'obbp_manage_rfqs', 'obbp-rfqs', [$this, 'rfqs']);
        add_submenu_page('obbp-dashboard', __('Quotes', 'obydullah-b2b-portal'), __('Quotes', 'obydullah-b2b-portal'), 'obbp_manage_quotes', 'obbp-quotes', [$this, 'quotes']);
        add_submenu_page('obbp-dashboard', __('Purchase Orders', 'obydullah-b2b-portal'), __('Purchase Orders', 'obydullah-b2b-portal'), 'obbp_manage_purchase_orders', 'obbp-purchase-orders', [$this, 'purchase_orders']);
        add_submenu_page('obbp-dashboard', __('Activity Log', 'obydullah-b2b-portal'), __('Activity Log', 'obydullah-b2b-portal'), 'obbp_view_activity', 'obbp-activity', [$this, 'activity']);
        add_submenu_page('obbp-dashboard', __('Import / Export', 'obydullah-b2b-portal'), __('Import / Export', 'obydullah-b2b-portal'), 'obbp_import_csv', 'obbp-transfer', [$this, 'transfer']);
        add_submenu_page('obbp-dashboard', __('Settings', 'obydullah-b2b-portal'), __('Settings', 'obydullah-b2b-portal'), 'obbp_manage_settings', 'obbp-settings', [$this, 'settings']);
    }

    public function highlight_catalog_parent($parent_file)
    {
        $screen = get_current_screen();
        if ($screen && 'obbp_product' === $screen->post_type) {
            return 'obbp-dashboard';
        }
        return $parent_file;
    }

    public function highlight_catalog_submenu($submenu_file)
    {
        $screen = get_current_screen();
        if ($screen && 'obbp_product' === $screen->post_type) {
            if (!empty($screen->taxonomy)) {
                return 'edit-tags.php?taxonomy=' . $screen->taxonomy . '&post_type=obbp_product';
            }
            return 'edit.php?post_type=obbp_product';
        }
        return $submenu_file;
    }

    public function assets($hook)
    {
        if (!$this->is_plugin_screen($hook)) {
            return;
        }
        wp_enqueue_style('obbp-admin', OBBP_URL . 'assets/css/obbp-admin.css', [], OBBP_VERSION);
    }

    public function body_class($classes)
    {
        if ($this->is_plugin_screen()) {
            $classes .= ' obbp-admin-page';
        }

        return $classes;
    }

    private function is_plugin_screen($hook = '')
    {
        if (str_contains((string) $hook, 'obbp-')) {
            return true;
        }

        $screen = get_current_screen();

        return $screen && (str_contains((string) $screen->id, 'obbp-') || 'obbp_product' === $screen->post_type);
    }

    /**
     * Verifies the navigation nonce when one is present.
     *
     * Every link rendered by OBBP_Admin::url() carries a nonce. The admin menu
     * itself is generated by WordPress via add_submenu_page() and cannot carry
     * one, so a missing nonce falls through to the per-screen capability check.
     * These parameters only select what to display; they never change data.
     */
    private function verify_navigation_nonce()
    {
        $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if ($nonce && !wp_verify_nonce($nonce, OBBP_NONCE_ADMIN)) {
            wp_die(esc_html__('Security check failed.', 'obydullah-b2b-portal'));
        }
    }

    public function notice()
    {
        if (!isset($_GET['obbp_message']) && !isset($_GET['obbp_error'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav params, nonce checked centrally
            return;
        }
        $this->verify_navigation_nonce();
        if (isset($_GET['obbp_message'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
            $message = sanitize_text_field(wp_unslash($_GET['obbp_message'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
            if ($message) {
                printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($message));
            }
        }
        if (isset($_GET['obbp_error'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
            $error = sanitize_text_field(wp_unslash($_GET['obbp_error'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
            if ($error) {
                printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($error));
            }
        }
    }

    public function handle_post()
    {
        $nonce = sanitize_text_field(wp_unslash($_POST['obbp_nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, OBBP_NONCE_ADMIN)) {
            wp_die(esc_html__('Security check failed.', 'obydullah-b2b-portal'));
        }
        $action = sanitize_key(wp_unslash($_POST['obbp_do'] ?? ''));
        $capabilities = [
            'save_company' => 'obbp_manage_companies',
            'save_contact' => 'obbp_manage_contacts',
            'company_status' => 'obbp_manage_companies',
            'save_price_list' => 'obbp_manage_price_lists',
            'save_price_items' => 'obbp_manage_price_lists',
            'rfq_status' => 'obbp_manage_rfqs',
            'create_quote' => 'obbp_manage_quotes',
            'save_quote' => 'obbp_manage_quotes',
            'send_quote' => 'obbp_manage_quotes',
            'issue_po' => 'obbp_manage_purchase_orders',
            'po_status' => 'obbp_manage_purchase_orders',
            'save_settings' => 'obbp_manage_settings',
            'resync_roles' => 'obbp_manage_settings',
        ];
        if (!isset($capabilities[$action]) || !current_user_can($capabilities[$action])) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'obydullah-b2b-portal'));
        }
        $result = $this->run_action($action);
        $redirect = $this->redirect_for($action);
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('obbp_error', rawurlencode($result->get_error_message()), $redirect));
            exit;
        }
        $message = is_string($result) ? $result : __('Changes saved.', 'obydullah-b2b-portal');
        if (is_array($result) && isset($result['message'])) {
            $message = $result['message'];
            $redirect = $result['redirect'] ?? $redirect;
        }
        wp_safe_redirect(add_query_arg('obbp_message', rawurlencode($message), $redirect));
        exit;
    }

    private function run_action($action)
    {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['obbp_nonce'] ?? '')), OBBP_NONCE_ADMIN)) {
            return new WP_Error('obbp_bad_nonce', __('Security check failed.', 'obydullah-b2b-portal'));
        }
        switch ($action) {
            case 'save_company':
                return $this->save_company();
            case 'save_contact':
                return $this->save_contact();
            case 'company_status':
                return $this->company_status();
            case 'save_price_list':
                return $this->save_price_list();
            case 'save_price_items':
                return $this->save_price_items();
            case 'rfq_status':
                return OBBP_Workflow::transition_rfq(absint(wp_unslash($_POST['rfq_id'] ?? 0)), sanitize_key(wp_unslash($_POST['status'] ?? '')), sanitize_textarea_field(wp_unslash($_POST['reason'] ?? '')));
            case 'create_quote':
                $id = OBBP_Workflow::create_quote(absint(wp_unslash($_POST['rfq_id'] ?? 0)));
                return is_wp_error($id) ? $id : ['message' => __('Draft quote created.', 'obydullah-b2b-portal'), 'redirect' => $this->url('obbp-quotes', ['view' => 'edit', 'id' => $id])];
            case 'save_quote':
                return OBBP_Workflow::save_quote(absint(wp_unslash($_POST['quote_id'] ?? 0)), wp_unslash($_POST));
            case 'send_quote':
                return OBBP_Workflow::send_quote(absint(wp_unslash($_POST['quote_id'] ?? 0)));
            case 'issue_po':
                return OBBP_Workflow::issue_po(absint(wp_unslash($_POST['po_id'] ?? 0)));
            case 'po_status':
                $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
                return 'issued' === $status ? OBBP_Workflow::issue_po(absint(wp_unslash($_POST['po_id'] ?? 0))) : OBBP_Workflow::transition_po(absint(wp_unslash($_POST['po_id'] ?? 0)), $status);
            case 'save_settings':
                return $this->save_settings();
            case 'resync_roles':
                OBBP_Roles::grant_admin_capabilities();
                return __('Roles and capabilities synchronized.', 'obydullah-b2b-portal');
        }
        return new WP_Error('obbp_unknown_action', __('Unknown action.', 'obydullah-b2b-portal'));
    }

    private function redirect_for($action)
    {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['obbp_nonce'] ?? '')), OBBP_NONCE_ADMIN)) {
            return $this->url('obbp-dashboard');
        }
        $id = absint(wp_unslash($_POST['id'] ?? $_POST['company_id'] ?? $_POST['quote_id'] ?? $_POST['rfq_id'] ?? $_POST['po_id'] ?? 0));
        $map = [
            'save_company' => ['obbp-companies', $id ? ['view' => 'edit', 'id' => $id] : ['view' => 'add']],
            'save_contact' => ['obbp-companies', ['view' => 'edit', 'id' => absint(wp_unslash($_POST['company_id'] ?? 0)), 'tab' => 'contacts']],
            'company_status' => ['obbp-companies', ['view' => 'edit', 'id' => $id]],
            'save_price_list' => ['obbp-price-lists', $id ? ['view' => 'edit', 'id' => $id] : []],
            'save_price_items' => ['obbp-price-lists', ['view' => 'edit', 'id' => $id]],
            'rfq_status' => ['obbp-rfqs', ['view' => 'edit', 'id' => $id]],
            'create_quote' => ['obbp-rfqs', ['view' => 'edit', 'id' => $id]],
            'save_quote' => ['obbp-quotes', ['view' => 'edit', 'id' => $id]],
            'send_quote' => ['obbp-quotes', ['view' => 'edit', 'id' => $id]],
            'issue_po' => ['obbp-purchase-orders', ['view' => 'edit', 'id' => $id]],
            'po_status' => ['obbp-purchase-orders', ['view' => 'edit', 'id' => $id]],
            'save_settings' => ['obbp-settings', []],
            'resync_roles' => ['obbp-settings', []],
        ];
        list($page, $args) = $map[$action];
        return $this->url($page, $args);
    }

    public function dashboard()
    {
        $this->cap('obbp_view_dashboard');
        $this->verify_navigation_nonce();
        $counts = OBBP_Repository::admin_counts();
        ?>
        <div class="wrap obbp-admin">
            <h1><?php esc_html_e('B2B Portal Dashboard', 'obydullah-b2b-portal'); ?></h1>
            <div class="obbp-metrics">
                <?php foreach ($counts as $label => $count) : ?>
                    <div class="obbp-metric"><strong><?php echo esc_html(number_format_i18n($count)); ?></strong><span><?php echo esc_html($label); ?></span></div>
                <?php endforeach; ?>
            </div>
            <h2><?php esc_html_e('Recent activity', 'obydullah-b2b-portal'); ?></h2>
            <?php $this->activity_table(OBBP_Repository::activity(['limit' => 25])); ?>
        </div>
        <?php
    }

    public function companies()
    {
        $this->cap('obbp_manage_companies');
        $this->verify_navigation_nonce();
        $view = sanitize_key(wp_unslash($_GET['view'] ?? 'list')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
        if (in_array($view, ['add', 'edit'], true)) {
            $this->company_form(absint(wp_unslash($_GET['id'] ?? 0))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
            return;
        }
        $status = sanitize_key(wp_unslash($_GET['status'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
        if ($status && !array_key_exists($status, OBBP_Repository::status_labels('company'))) {
            $status = '';
        }
        $rows = OBBP_Repository::companies_list(['status' => $status, 'search' => $search]);
        ?>
        <div class="wrap obbp-admin">
            <h1 class="wp-heading-inline"><?php esc_html_e('Companies', 'obydullah-b2b-portal'); ?></h1> <a class="page-title-action" href="<?php echo esc_url($this->url('obbp-companies', ['view' => 'add'])); ?>"><?php esc_html_e('Add Company', 'obydullah-b2b-portal'); ?></a>
            <form method="get" class="obbp-filters"><input type="hidden" name="page" value="obbp-companies"><select name="status"><option value=""><?php esc_html_e('All statuses', 'obydullah-b2b-portal'); ?></option><?php foreach (OBBP_Repository::status_labels('company') as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search', 'obydullah-b2b-portal'); ?>"><button class="button"><?php esc_html_e('Filter', 'obydullah-b2b-portal'); ?></button></form>
            <table class="widefat fixed striped"><thead><tr><th><?php esc_html_e('Company', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Email', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Status', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Contacts', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Created', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody>
            <?php if (!$rows) : ?><tr><td colspan="5"><?php esc_html_e('No companies found.', 'obydullah-b2b-portal'); ?></td></tr><?php endif; ?>
            <?php foreach ($rows as $row) : ?><tr><td><strong><a href="<?php echo esc_url($this->url('obbp-companies', ['view' => 'edit', 'id' => $row['id']])); ?>"><?php echo esc_html($row['name']); ?></a></strong></td><td><a href="mailto:<?php echo esc_attr($row['email']); ?>"><?php echo esc_html($row['email']); ?></a></td><td><?php $this->badge($row['status'], 'company'); ?></td><td><?php echo esc_html($row['contact_count']); ?></td><td class="obbp-when"><?php echo esc_html($row['created_at']); ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    public function company_form($id = 0)
    {
        $this->cap('obbp_manage_companies');
        $this->verify_navigation_nonce();
        $company = $id ? OBBP_Repository::row('companies', $id) : null;
        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'details')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
        if ('contacts' === $tab) {
            $this->cap('obbp_manage_contacts');
        $this->verify_navigation_nonce();
        }
        $is_edit = $company && 'edit' === sanitize_key(wp_unslash($_GET['view'] ?? 'edit')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
        ?>
        <div class="wrap obbp-admin">
            <?php $this->back_link('obbp-companies', __('Companies', 'obydullah-b2b-portal')); ?>
            <h1><?php echo $is_edit ? esc_html($company['name']) : esc_html__('Add Company', 'obydullah-b2b-portal'); ?></h1>
            <?php if ($is_edit) : ?>
                <nav class="nav-tab-wrapper"><a class="nav-tab <?php echo 'contacts' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($this->url('obbp-companies', ['view' => 'edit', 'id' => $id])); ?>"><?php esc_html_e('Details', 'obydullah-b2b-portal'); ?></a><a class="nav-tab <?php echo 'contacts' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($this->url('obbp-companies', ['view' => 'edit', 'id' => $id, 'tab' => 'contacts'])); ?>"><?php esc_html_e('Contacts', 'obydullah-b2b-portal'); ?></a></nav>
            <?php endif; ?>
            <?php if ($is_edit && 'contacts' === $tab) { $this->contacts_panel($company); } else { $this->company_fields($company); } ?>
        </div>
        <?php
    }

    private function company_fields($company)
    {
        $settings = get_option(OBBP_OPTION_SETTINGS, []);
        $price_lists = OBBP_Repository::rows('price_lists', ['is_active' => 1], ['orderby' => 'name', 'order' => 'ASC', 'limit' => 200]);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_action')); ?>" class="obbp-form">
            <input type="hidden" name="obbp_do" value="save_company"><input type="hidden" name="id" value="<?php echo esc_attr($company['id'] ?? 0); ?>"><?php wp_nonce_field(OBBP_NONCE_ADMIN, 'obbp_nonce'); ?>
            <table class="form-table" role="presentation"><tbody>
            <?php $this->text_field('name', __('Company name', 'obydullah-b2b-portal'), $company['name'] ?? '', true); ?>
            <?php $this->email_field('email', __('Email', 'obydullah-b2b-portal'), $company['email'] ?? '', true); ?>
            <?php $this->text_field('phone', __('Phone', 'obydullah-b2b-portal'), $company['phone'] ?? ''); ?>
            <?php $this->text_field('website', __('Website', 'obydullah-b2b-portal'), $company['website'] ?? ''); ?>
            <?php $this->text_field('tax_id', __('Tax ID', 'obydullah-b2b-portal'), $company['tax_id'] ?? ''); ?>
            <?php $this->text_field('address_line1', __('Address line 1', 'obydullah-b2b-portal'), $company['address_line1'] ?? ''); ?>
            <?php $this->text_field('address_line2', __('Address line 2', 'obydullah-b2b-portal'), $company['address_line2'] ?? ''); ?>
            <?php $this->text_field('city', __('City', 'obydullah-b2b-portal'), $company['city'] ?? ''); ?>
            <?php $this->text_field('state', __('State', 'obydullah-b2b-portal'), $company['state'] ?? ''); ?>
            <?php $this->text_field('postcode', __('Postcode', 'obydullah-b2b-portal'), $company['postcode'] ?? ''); ?>
            <tr><th><label for="obbp_country"><?php esc_html_e('Country', 'obydullah-b2b-portal'); ?></label></th><td><input id="obbp_country" name="country" maxlength="2" value="<?php echo esc_attr(strtoupper($company['country'] ?? ($settings['default_country'] ?? 'BD'))); ?>"></td></tr>
            <tr><th><label for="obbp_price_list"><?php esc_html_e('Price list', 'obydullah-b2b-portal'); ?></label></th><td><select id="obbp_price_list" name="price_list_id"><option value="0"><?php esc_html_e('Use default list', 'obydullah-b2b-portal'); ?></option><?php foreach ($price_lists as $list) : ?><option value="<?php echo esc_attr($list['id']); ?>" <?php selected($company['price_list_id'] ?? 0, $list['id']); ?>><?php echo esc_html($list['name']); ?></option><?php endforeach; ?></select></td></tr>
            <tr><th><label for="obbp_currency"><?php esc_html_e('Currency', 'obydullah-b2b-portal'); ?></label></th><td><input id="obbp_currency" name="currency" maxlength="10" value="<?php echo esc_attr(strtoupper($company['currency'] ?? '')); ?>" placeholder="<?php echo esc_attr($settings['default_currency'] ?? 'BDT'); ?>"></td></tr>
            <?php $this->number_field('credit_limit', __('Credit limit', 'obydullah-b2b-portal'), $company['credit_limit'] ?? '0.00', '0.01'); ?>
            <?php $this->text_field('payment_terms', __('Payment terms', 'obydullah-b2b-portal'), $company['payment_terms'] ?? ''); ?>
            <tr><th><?php esc_html_e('Email notifications', 'obydullah-b2b-portal'); ?></th><td><label><input type="checkbox" name="email_enabled" value="1" <?php checked($company['email_enabled'] ?? 1, 1); ?>> <?php esc_html_e('Send portal notifications', 'obydullah-b2b-portal'); ?></label></td></tr>
            </tbody></table>
            <?php submit_button($company ? __('Update Company', 'obydullah-b2b-portal') : __('Create Company', 'obydullah-b2b-portal')); ?>
        </form>
        <?php
        if ($company) {
            $this->company_status_panel($company);
        }
    }

    private function company_status_panel($company)
    {
        ?>
        <div class="obbp-panel"><h2><?php esc_html_e('Approval', 'obydullah-b2b-portal'); ?></h2><p><?php $this->badge($company['status'], 'company'); ?></p>
        <?php if ($company['status_note']) : ?><p class="obbp-muted"><?php echo esc_html($company['status_note']); ?></p><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_action')); ?>"><input type="hidden" name="obbp_do" value="company_status"><input type="hidden" name="id" value="<?php echo esc_attr($company['id']); ?>"><?php wp_nonce_field(OBBP_NONCE_ADMIN, 'obbp_nonce'); ?><input type="text" name="note" placeholder="<?php esc_attr_e('Required for rejection or suspension', 'obydullah-b2b-portal'); ?>">
        <?php if ('approved' === $company['status']) : ?><button class="button" name="status" value="suspended"><?php esc_html_e('Suspend', 'obydullah-b2b-portal'); ?></button><?php elseif ('pending' === $company['status']) : ?><button class="button button-primary" name="status" value="approved"><?php esc_html_e('Approve', 'obydullah-b2b-portal'); ?></button><button class="button" name="status" value="rejected"><?php esc_html_e('Reject', 'obydullah-b2b-portal'); ?></button><?php else : ?><button class="button button-primary" name="status" value="approved"><?php esc_html_e('Approve', 'obydullah-b2b-portal'); ?></button><button class="button" name="status" value="rejected"><?php esc_html_e('Reject', 'obydullah-b2b-portal'); ?></button><?php endif; ?></form></div>
        <?php
    }

    private function contacts_panel($company)
    {
        global $wpdb;
        $contacts = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE company_id = %d AND deleted_at IS NULL ORDER BY is_primary DESC, full_name ASC', OBBP_Repository::table('company_contacts'), $company['id']), ARRAY_A);
        $edit_id = absint(wp_unslash($_GET['contact_id'] ?? 0)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
        $contact = $edit_id ? OBBP_Repository::row('company_contacts', $edit_id) : null;
        if ($contact && (int) $contact['company_id'] !== (int) $company['id']) {
            $contact = null;
        }
        ?>
        <div class="obbp-panel"><h2><?php esc_html_e('Contacts', 'obydullah-b2b-portal'); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e('Name', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Email', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Primary', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Can Approve', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('User', 'obydullah-b2b-portal'); ?></th><th></th></tr></thead><tbody><?php foreach ($contacts as $row) : ?><tr><td><?php echo esc_html($row['full_name']); ?></td><td><?php echo esc_html($row['email']); ?></td><td><?php echo wp_kses_post($this->flag($row['is_primary'])); ?></td><td><?php echo wp_kses_post($this->flag($row['can_approve_quotes'])); ?></td><td><?php $linked_user = $row['user_id'] ? get_userdata($row['user_id']) : null; echo $linked_user ? esc_html($linked_user->display_name) : wp_kses_post('<span class="obbp-dash" aria-hidden="true">&mdash;</span>'); ?></td><td><a href="<?php echo esc_url($this->url('obbp-companies', ['view' => 'edit', 'id' => $company['id'], 'tab' => 'contacts', 'contact_id' => $row['id']])); ?>"><?php esc_html_e('Edit', 'obydullah-b2b-portal'); ?></a></td></tr><?php endforeach; ?></tbody></table></div>
        <div class="obbp-panel"><h2><?php echo $contact ? esc_html__('Edit Contact', 'obydullah-b2b-portal') : esc_html__('Add Contact', 'obydullah-b2b-portal'); ?></h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_action')); ?>"><input type="hidden" name="obbp_do" value="save_contact"><input type="hidden" name="id" value="<?php echo esc_attr($contact['id'] ?? 0); ?>"><input type="hidden" name="company_id" value="<?php echo esc_attr($company['id']); ?>"><?php wp_nonce_field(OBBP_NONCE_ADMIN, 'obbp_nonce'); ?><table class="form-table"><tbody><?php $this->text_field('full_name', __('Full name', 'obydullah-b2b-portal'), $contact['full_name'] ?? '', true); ?><?php $this->email_field('email', __('Email', 'obydullah-b2b-portal'), $contact['email'] ?? '', true); ?><?php $this->text_field('phone', __('Phone', 'obydullah-b2b-portal'), $contact['phone'] ?? ''); ?><?php $this->text_field('job_title', __('Job title', 'obydullah-b2b-portal'), $contact['job_title'] ?? ''); ?><tr><th><label for="obbp_contact_user"><?php esc_html_e('Portal user', 'obydullah-b2b-portal'); ?></label></th><td><?php wp_dropdown_users(['name' => 'user_id', 'id' => 'obbp_contact_user', 'selected' => absint($contact['user_id'] ?? 0), 'show_option_none' => __('&mdash; Not linked &mdash;', 'obydullah-b2b-portal'), 'option_none_value' => '0', 'show' => 'display_name_with_login']); ?><p class="description"><?php esc_html_e('Link an existing WordPress user to grant portal access. Create users under Users &rarr; Add New; the plugin never creates accounts.', 'obydullah-b2b-portal'); ?></p></td></tr><tr><th><?php esc_html_e('Permissions', 'obydullah-b2b-portal'); ?></th><td><label><input type="checkbox" name="is_primary" value="1" <?php checked($contact['is_primary'] ?? 0, 1); ?>> <?php esc_html_e('Primary contact', 'obydullah-b2b-portal'); ?></label><br><label><input type="checkbox" name="can_approve_quotes" value="1" <?php checked($contact['can_approve_quotes'] ?? 0, 1); ?>> <?php esc_html_e('Can accept or reject quotes', 'obydullah-b2b-portal'); ?></label><br><label><input type="checkbox" name="email_enabled" value="1" <?php checked($contact['status'] ?? 'active', 'active'); ?>> <?php esc_html_e('Active', 'obydullah-b2b-portal'); ?></label></td></tr></tbody></table><?php submit_button($contact ? __('Update Contact', 'obydullah-b2b-portal') : __('Add Contact', 'obydullah-b2b-portal')); ?></form></div>
        <?php
    }

    public function price_lists()
    {
        $this->cap('obbp_manage_price_lists');
        $this->verify_navigation_nonce();
        $view = sanitize_key(wp_unslash($_GET['view'] ?? 'list')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
        if (in_array($view, ['add', 'edit'], true)) {
            $this->price_list_form(absint(wp_unslash($_GET['id'] ?? 0))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
            return;
        }
        $rows = OBBP_Repository::rows('price_lists', [], ['orderby' => 'name', 'order' => 'ASC', 'limit' => 200]);
        ?>
        <div class="wrap obbp-admin"><h1 class="wp-heading-inline"><?php esc_html_e('Price Lists', 'obydullah-b2b-portal'); ?></h1> <a class="page-title-action" href="<?php echo esc_url($this->url('obbp-price-lists', ['view' => 'add'])); ?>"><?php esc_html_e('Add Price List', 'obydullah-b2b-portal'); ?></a><table class="widefat striped"><thead><tr><th><?php esc_html_e('Name', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Currency', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Default', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Active', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Valid', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php foreach ($rows as $row) : ?><tr><td><a href="<?php echo esc_url($this->url('obbp-price-lists', ['view' => 'edit', 'id' => $row['id']])); ?>"><strong><?php echo esc_html($row['name']); ?></strong></a></td><td><?php echo esc_html($row['currency']); ?></td><td><?php echo wp_kses_post($this->flag($row['is_default'])); ?></td><td><?php echo wp_kses_post($this->flag($row['is_active'])); ?></td><td><?php echo esc_html(trim($row['valid_from'] . ' – ' . $row['valid_to'], ' –')); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php
    }

    private function price_list_form($id = 0)
    {
        global $wpdb;
        $list = $id ? OBBP_Repository::row('price_lists', $id) : null;
        $items = $id ? $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE price_list_id = %d ORDER BY id ASC', OBBP_Repository::table('price_list_items'), $id), ARRAY_A) : [];
        $products = get_posts(['post_type' => 'obbp_product', 'post_status' => ['publish', 'draft', 'private'], 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC']);
        $items[] = ['id' => 0, 'product_id' => 0, 'unit_price' => '', 'min_qty' => 1, 'qty_step' => 1, 'lead_time_days' => 0, 'is_active' => 1, 'note' => ''];
        ?>
        <div class="wrap obbp-admin"><?php $this->back_link('obbp-price-lists', __('Price Lists', 'obydullah-b2b-portal')); ?><h1><?php echo $list ? esc_html($list['name']) : esc_html__('Add Price List', 'obydullah-b2b-portal'); ?></h1>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_action')); ?>" class="obbp-form"><input type="hidden" name="obbp_do" value="save_price_list"><input type="hidden" name="id" value="<?php echo esc_attr($list['id'] ?? 0); ?>"><?php wp_nonce_field(OBBP_NONCE_ADMIN, 'obbp_nonce'); ?><table class="form-table"><tbody><?php $this->text_field('name', __('Name', 'obydullah-b2b-portal'), $list['name'] ?? '', true); ?><tr><th><label for="obbp_pl_description"><?php esc_html_e('Description', 'obydullah-b2b-portal'); ?></label></th><td><textarea id="obbp_pl_description" name="description" rows="3" class="large-text"><?php echo esc_textarea($list['description'] ?? ''); ?></textarea></td></tr><?php $this->text_field('currency', __('Currency', 'obydullah-b2b-portal'), $list['currency'] ?? ''); ?><tr><th><?php esc_html_e('Options', 'obydullah-b2b-portal'); ?></th><td><label><input type="checkbox" name="is_default" value="1" <?php checked($list['is_default'] ?? 0, 1); ?>> <?php esc_html_e('Default list', 'obydullah-b2b-portal'); ?></label><br><label><input type="checkbox" name="is_active" value="1" <?php checked($list['is_active'] ?? 1, 1); ?>> <?php esc_html_e('Active', 'obydullah-b2b-portal'); ?></label></td></tr><?php $this->text_field('valid_from', __('Valid from', 'obydullah-b2b-portal'), $list['valid_from'] ?? '', false, 'date'); $this->text_field('valid_to', __('Valid to', 'obydullah-b2b-portal'), $list['valid_to'] ?? '', false, 'date'); ?></tbody></table><?php submit_button($list ? __('Update Price List', 'obydullah-b2b-portal') : __('Create Price List', 'obydullah-b2b-portal')); ?></form>
        <?php if ($list) : ?><div class="obbp-panel"><h2><?php esc_html_e('Catalog Prices', 'obydullah-b2b-portal'); ?></h2><?php if (!$products) : ?><p><?php esc_html_e('Create catalog products before adding prices.', 'obydullah-b2b-portal'); ?></p><?php else : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_action')); ?>"><input type="hidden" name="obbp_do" value="save_price_items"><input type="hidden" name="id" value="<?php echo esc_attr($list['id']); ?>"><?php wp_nonce_field(OBBP_NONCE_ADMIN, 'obbp_nonce'); ?><div class="obbp-scroll"><table class="widefat striped obbp-price-grid"><thead><tr><th><?php esc_html_e('Product', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Unit price', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Min qty', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Step', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Lead days', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Note', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php foreach ($items as $index => $item) : ?><tr><td><select name="items[<?php echo esc_attr($index); ?>][product_id]" required><option value="0"><?php esc_html_e('Select product', 'obydullah-b2b-portal'); ?></option><?php foreach ($products as $product) : ?><option value="<?php echo esc_attr($product->ID); ?>" <?php selected($item['product_id'], $product->ID); ?>><?php echo esc_html($product->post_title); ?></option><?php endforeach; ?></select></td><td><input name="items[<?php echo esc_attr($index); ?>][unit_price]" type="number" min="0" step="0.0001" value="<?php echo esc_attr($item['unit_price']); ?>" required></td><td><input name="items[<?php echo esc_attr($index); ?>][min_qty]" type="number" min="0" step="0.0001" value="<?php echo esc_attr($item['min_qty']); ?>"></td><td><input name="items[<?php echo esc_attr($index); ?>][qty_step]" type="number" min="0" step="0.0001" value="<?php echo esc_attr($item['qty_step']); ?>"></td><td><input name="items[<?php echo esc_attr($index); ?>][lead_time_days]" type="number" min="0" value="<?php echo esc_attr($item['lead_time_days']); ?>"></td><td><input name="items[<?php echo esc_attr($index); ?>][note]" value="<?php echo esc_attr($item['note']); ?>"></td></tr><?php endforeach; ?></tbody></table></div><p><button class="button" type="submit"><?php esc_html_e('Save Prices', 'obydullah-b2b-portal'); ?></button></p></form><?php endif; ?></div><?php endif; ?>
        </div>
        <?php
    }

    public function rfqs()
    {
        $this->cap('obbp_manage_rfqs');
        $this->verify_navigation_nonce();
        if ('edit' === sanitize_key(wp_unslash($_GET['view'] ?? ''))) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
            $this->rfq_detail(absint(wp_unslash($_GET['id'] ?? 0))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
            return;
        }
        $status = sanitize_key(wp_unslash($_GET['status'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
        if ($status && !array_key_exists($status, OBBP_Repository::status_labels('rfq'))) {
            $status = '';
        }
        $rows = OBBP_Repository::rfqs_list(['status' => $status]);
        ?>
        <div class="wrap obbp-admin"><h1><?php esc_html_e('RFQs', 'obydullah-b2b-portal'); ?></h1><form method="get" class="obbp-filters"><input type="hidden" name="page" value="obbp-rfqs"><select name="status"><option value=""><?php esc_html_e('All statuses', 'obydullah-b2b-portal'); ?></option><?php foreach (OBBP_Repository::status_labels('rfq') as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><button class="button"><?php esc_html_e('Filter', 'obydullah-b2b-portal'); ?></button></form><table class="widefat striped"><thead><tr><th><?php esc_html_e('RFQ', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Company', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Items', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Delivery', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Status', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Submitted', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php foreach ($rows as $row) : ?><tr><td><strong><a href="<?php echo esc_url($this->url('obbp-rfqs', ['view' => 'edit', 'id' => $row['id']])); ?>"><?php echo esc_html($row['rfq_number']); ?></a></strong></td><td><?php echo esc_html($row['company_name']); ?></td><td><?php echo esc_html($row['item_count']); ?></td><td class="obbp-when"><?php echo esc_html($row['requested_delivery_date']); ?></td><td><?php $this->badge($row['status'], 'rfq'); ?></td><td class="obbp-when"><?php echo esc_html($row['submitted_at']); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php
    }

    private function rfq_detail($id)
    {
        global $wpdb;
        $rfq = OBBP_Repository::row('rfqs', $id);
        if (!$rfq) {
            $this->missing();
            return;
        }
        $company = OBBP_Repository::row('companies', $rfq['company_id']);
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE rfq_id = %d AND deleted_at IS NULL ORDER BY id ASC', OBBP_Repository::table('rfq_items'), $id), ARRAY_A);
        $quotes = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE rfq_id = %d AND deleted_at IS NULL ORDER BY id DESC', OBBP_Repository::table('quotes'), $id), ARRAY_A);
        ?>
        <div class="wrap obbp-admin"><?php $this->back_link('obbp-rfqs', __('RFQs', 'obydullah-b2b-portal')); ?><h1><?php echo esc_html($rfq['rfq_number']); ?> <?php $this->badge($rfq['status'], 'rfq'); ?></h1><p><strong><?php echo esc_html($company['name']); ?></strong> · <?php echo esc_html($rfq['requested_delivery_date']); ?></p><?php if ($rfq['notes']) : ?><div class="obbp-panel"><h3><?php esc_html_e('Customer notes', 'obydullah-b2b-portal'); ?></h3><p><?php echo nl2br(esc_html($rfq['notes'])); ?></p></div><?php endif; ?><div class="obbp-panel"><h2><?php esc_html_e('Items', 'obydullah-b2b-portal'); ?></h2><table class="widefat striped"><thead><tr><th>SKU</th><th><?php esc_html_e('Product', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Quantity', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Note', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php foreach ($items as $item) : ?><tr><td><?php echo esc_html($item['sku']); ?></td><td><?php echo esc_html($item['name']); ?></td><td><?php echo esc_html($item['quantity'] . ' ' . $item['unit']); ?></td><td><?php echo esc_html($item['note']); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <div class="obbp-panel"><h2><?php esc_html_e('Workflow', 'obydullah-b2b-portal'); ?></h2><?php if (in_array($rfq['status'], ['submitted', 'under_review', 'quoted'], true)) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_action')); ?>" class="obbp-inline-form"><input type="hidden" name="obbp_do" value="create_quote"><input type="hidden" name="rfq_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field(OBBP_NONCE_ADMIN, 'obbp_nonce'); ?><button class="button button-primary"><?php esc_html_e('Create Quote', 'obydullah-b2b-portal'); ?></button></form><?php endif; ?><?php if (in_array($rfq['status'], ['submitted', 'under_review', 'quoted'], true)) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_action')); ?>" class="obbp-inline-form"><input type="hidden" name="obbp_do" value="rfq_status"><input type="hidden" name="rfq_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field(OBBP_NONCE_ADMIN, 'obbp_nonce'); ?><input type="text" name="reason" placeholder="<?php esc_attr_e('Reason', 'obydullah-b2b-portal'); ?>"><button class="button" name="status" value="rejected"><?php esc_html_e('Reject', 'obydullah-b2b-portal'); ?></button><?php if ('submitted' === $rfq['status']) : ?><button class="button" name="status" value="under_review"><?php esc_html_e('Start Review', 'obydullah-b2b-portal'); ?></button><?php endif; ?><button class="button" name="status" value="closed"><?php esc_html_e('Close', 'obydullah-b2b-portal'); ?></button></form><?php endif; ?><h3><?php esc_html_e('Quotes', 'obydullah-b2b-portal'); ?></h3><ul><?php foreach ($quotes as $quote) : ?><li><a href="<?php echo esc_url($this->url('obbp-quotes', ['view' => 'edit', 'id' => $quote['id']])); ?>"><?php echo esc_html($quote['quote_number']); ?></a> <?php $this->badge($quote['status'], 'quote'); ?></li><?php endforeach; ?></ul></div></div>
        <?php
    }

    public function quotes()
    {
        global $wpdb;
        $this->cap('obbp_manage_quotes');
        $this->verify_navigation_nonce();
        if ('edit' === sanitize_key(wp_unslash($_GET['view'] ?? ''))) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
            $this->quote_detail(absint(wp_unslash($_GET['id'] ?? 0))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
            return;
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT q.*, c.name company_name FROM %i q INNER JOIN %i c ON c.id = q.company_id WHERE q.deleted_at IS NULL ORDER BY q.created_at DESC LIMIT 200', OBBP_Repository::table('quotes'), OBBP_Repository::table('companies')), ARRAY_A);
        ?>
        <div class="wrap obbp-admin"><h1><?php esc_html_e('Quotes', 'obydullah-b2b-portal'); ?></h1><table class="widefat striped"><thead><tr><th><?php esc_html_e('Quote', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Company', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Total', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Status', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Valid until', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php foreach ($rows as $row) : ?><tr><td><strong><a href="<?php echo esc_url($this->url('obbp-quotes', ['view' => 'edit', 'id' => $row['id']])); ?>"><?php echo esc_html($row['quote_number']); ?></a></strong></td><td><?php echo esc_html($row['company_name']); ?></td><td class="obbp-num"><?php echo esc_html($row['currency'] . ' ' . number_format_i18n((float) $row['grand_total'], 2)); ?></td><td><?php $this->badge($row['status'], 'quote'); ?></td><td class="obbp-when"><?php echo esc_html($row['valid_until']); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php
    }

    private function quote_detail($id)
    {
        global $wpdb;
        $quote = OBBP_Repository::row('quotes', $id);
        if (!$quote) {
            $this->missing();
            return;
        }
        $company = OBBP_Repository::row('companies', $quote['company_id']);
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE quote_id = %d AND deleted_at IS NULL ORDER BY line_sort ASC, id ASC', OBBP_Repository::table('quote_items'), $id), ARRAY_A);
        $products = get_posts(['post_type' => 'obbp_product', 'post_status' => ['publish', 'draft', 'private'], 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC']);
        $items[] = ['id' => 0, 'rfq_item_id' => 0, 'product_id' => 0, 'quantity' => 1, 'unit_price' => 0, 'line_discount' => 0, 'tax_rate' => 0, 'lead_time_days' => 0, 'note' => '', 'line_total' => 0, 'line_tax_total' => 0];
        ?>
        <div class="wrap obbp-admin"><?php $this->back_link('obbp-quotes', __('Quotes', 'obydullah-b2b-portal')); ?><h1><?php echo esc_html($quote['quote_number']); ?> <?php $this->badge($quote['status'], 'quote'); ?></h1><p><strong><?php echo esc_html($company['name']); ?></strong></p>
        <?php if ('draft' === $quote['status']) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_action')); ?>"><input type="hidden" name="obbp_do" value="save_quote"><input type="hidden" name="quote_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field(OBBP_NONCE_ADMIN, 'obbp_nonce'); ?><div class="obbp-panel"><h2><?php esc_html_e('Quote Details', 'obydullah-b2b-portal'); ?></h2><div class="obbp-form-grid"><label><?php esc_html_e('Issue date', 'obydullah-b2b-portal'); ?><input type="date" name="issue_date" value="<?php echo esc_attr($quote['issue_date']); ?>"></label><label><?php esc_html_e('Valid until', 'obydullah-b2b-portal'); ?><input type="date" name="valid_until" value="<?php echo esc_attr($quote['valid_until']); ?>" required></label><label><?php esc_html_e('Discount type', 'obydullah-b2b-portal'); ?><select name="discount_type"><option value=""><?php esc_html_e('None', 'obydullah-b2b-portal'); ?></option><option value="fixed" <?php selected($quote['discount_type'], 'fixed'); ?>><?php esc_html_e('Fixed', 'obydullah-b2b-portal'); ?></option><option value="percent" <?php selected($quote['discount_type'], 'percent'); ?>><?php esc_html_e('Percent', 'obydullah-b2b-portal'); ?></option></select></label><label><?php esc_html_e('Discount', 'obydullah-b2b-portal'); ?><input type="number" step="0.0001" min="0" name="discount_value" value="<?php echo esc_attr($quote['discount_value']); ?>"></label><label><?php esc_html_e('Shipping', 'obydullah-b2b-portal'); ?><input type="number" step="0.01" min="0" name="shipping_total" value="<?php echo esc_attr($quote['shipping_total']); ?>"></label></div><label><?php esc_html_e('Payment terms', 'obydullah-b2b-portal'); ?><input type="text" name="payment_terms" value="<?php echo esc_attr($quote['payment_terms']); ?>"></label><label><?php esc_html_e('Delivery terms', 'obydullah-b2b-portal'); ?><input type="text" name="delivery_terms" value="<?php echo esc_attr($quote['delivery_terms']); ?>"></label><label><?php esc_html_e('Customer notes', 'obydullah-b2b-portal'); ?><textarea name="notes"><?php echo esc_textarea($quote['notes']); ?></textarea></label><label><?php esc_html_e('Internal notes', 'obydullah-b2b-portal'); ?><textarea name="internal_notes"><?php echo esc_textarea($quote['internal_notes']); ?></textarea></label></div><div class="obbp-panel"><h2><?php esc_html_e('Items', 'obydullah-b2b-portal'); ?></h2><div class="obbp-scroll"><table class="widefat striped obbp-price-grid"><thead><tr><th><?php esc_html_e('Product', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Qty', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Unit price', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Tax %', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Lead', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Note', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php foreach ($items as $index => $item) : ?><tr><td><select name="items[<?php echo esc_attr($index); ?>][product_id]" required><option value="0"><?php esc_html_e('Select product', 'obydullah-b2b-portal'); ?></option><?php foreach ($products as $product) : ?><option value="<?php echo esc_attr($product->ID); ?>" <?php selected($item['product_id'], $product->ID); ?>><?php echo esc_html($product->post_title); ?></option><?php endforeach; ?></select><input type="hidden" name="items[<?php echo esc_attr($index); ?>][rfq_item_id]" value="<?php echo esc_attr($item['rfq_item_id']); ?>"><input type="hidden" name="items[<?php echo esc_attr($index); ?>][list_price]" value="<?php echo esc_attr($item['list_price'] ?? 0); ?>"></td><td><input type="number" step="0.0001" min="0" name="items[<?php echo esc_attr($index); ?>][quantity]" value="<?php echo esc_attr($item['quantity']); ?>" required></td><td><input type="number" step="0.0001" min="0" name="items[<?php echo esc_attr($index); ?>][unit_price]" value="<?php echo esc_attr($item['unit_price']); ?>" required></td><td><input type="number" step="0.0001" min="0" name="items[<?php echo esc_attr($index); ?>][tax_rate]" value="<?php echo esc_attr($item['tax_rate']); ?>"></td><td><input type="number" min="0" name="items[<?php echo esc_attr($index); ?>][lead_time_days]" value="<?php echo esc_attr($item['lead_time_days']); ?>"></td><td><input name="items[<?php echo esc_attr($index); ?>][note]" value="<?php echo esc_attr($item['note']); ?>"></td></tr><?php endforeach; ?></tbody></table></div></div><p><button class="button button-primary" name="obbp_do" value="save_quote"><?php esc_html_e('Save Quote', 'obydullah-b2b-portal'); ?></button></p></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_action')); ?>"><input type="hidden" name="obbp_do" value="send_quote"><input type="hidden" name="quote_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field(OBBP_NONCE_ADMIN, 'obbp_nonce'); ?><button class="button button-primary"><?php esc_html_e('Send Quote to Customer', 'obydullah-b2b-portal'); ?></button></form>
        <?php else : ?><div class="obbp-panel"><h2><?php esc_html_e('Quote Summary', 'obydullah-b2b-portal'); ?></h2><p><?php esc_html_e('Subtotal', 'obydullah-b2b-portal'); ?>: <?php echo esc_html(number_format_i18n((float) $quote['subtotal'], 2)); ?></p><p><?php esc_html_e('Discount', 'obydullah-b2b-portal'); ?>: <?php echo esc_html(number_format_i18n((float) $quote['discount_total'], 2)); ?></p><p><?php esc_html_e('Tax', 'obydullah-b2b-portal'); ?>: <?php echo esc_html(number_format_i18n((float) $quote['tax_total'], 2)); ?></p><p><strong><?php esc_html_e('Total', 'obydullah-b2b-portal'); ?>: <?php echo esc_html($quote['currency'] . ' ' . number_format_i18n((float) $quote['grand_total'], 2)); ?></strong></p><?php $this->quote_items_table($items); ?></div><?php endif; ?>
        </div>
        <?php
    }

    public function purchase_orders()
    {
        global $wpdb;
        $this->cap('obbp_manage_purchase_orders');
        $this->verify_navigation_nonce();
        if ('edit' === sanitize_key(wp_unslash($_GET['view'] ?? ''))) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
            $this->po_detail(absint(wp_unslash($_GET['id'] ?? 0))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
            return;
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT p.*, c.name company_name FROM %i p INNER JOIN %i c ON c.id = p.company_id WHERE p.deleted_at IS NULL ORDER BY p.created_at DESC LIMIT 200', OBBP_Repository::table('purchase_orders'), OBBP_Repository::table('companies')), ARRAY_A);
        ?>
        <div class="wrap obbp-admin"><h1><?php esc_html_e('Purchase Orders', 'obydullah-b2b-portal'); ?></h1><table class="widefat striped"><thead><tr><th><?php esc_html_e('PO', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Company', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Total', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Status', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Promised ship', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php foreach ($rows as $row) : ?><tr><td><strong><a href="<?php echo esc_url($this->url('obbp-purchase-orders', ['view' => 'edit', 'id' => $row['id']])); ?>"><?php echo esc_html($row['po_number']); ?></a></strong></td><td><?php echo esc_html($row['company_name']); ?></td><td class="obbp-num"><?php echo esc_html($row['currency'] . ' ' . number_format_i18n((float) $row['grand_total'], 2)); ?></td><td><?php $this->badge($row['status'], 'purchase_order'); ?></td><td class="obbp-when"><?php echo esc_html($row['promised_ship_date']); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php
    }

    private function po_detail($id)
    {
        global $wpdb;
        $po = OBBP_Repository::row('purchase_orders', $id);
        if (!$po) {
            $this->missing();
            return;
        }
        $company = OBBP_Repository::row('companies', $po['company_id']);
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE purchase_order_id = %d AND deleted_at IS NULL ORDER BY line_sort ASC, id ASC', OBBP_Repository::table('purchase_order_items'), $id), ARRAY_A);
        $next = ['draft' => ['issued'], 'issued' => ['confirmed'], 'acknowledged' => ['in_production'], 'confirmed' => ['in_production'], 'in_production' => ['dispatched'], 'dispatched' => ['delivered'], 'delivered' => ['completed'], 'completed' => ['closed']];
        ?>
        <div class="wrap obbp-admin"><?php $this->back_link('obbp-purchase-orders', __('Purchase Orders', 'obydullah-b2b-portal')); ?><h1><?php echo esc_html($po['po_number']); ?> <?php $this->badge($po['status'], 'purchase_order'); ?></h1><p><strong><?php echo esc_html($company['name']); ?></strong> · <?php echo esc_html($po['currency'] . ' ' . number_format_i18n((float) $po['grand_total'], 2)); ?></p>
        <?php if (isset($next[$po['status']])) : ?><div class="obbp-panel"><h2><?php esc_html_e('Advance Order', 'obydullah-b2b-portal'); ?></h2><?php foreach ($next[$po['status']] as $status) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_action')); ?>" class="obbp-inline-form"><input type="hidden" name="obbp_do" value="po_status"><input type="hidden" name="po_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field(OBBP_NONCE_ADMIN, 'obbp_nonce'); ?><button class="button button-primary" name="status" value="<?php echo esc_attr($status); ?>"><?php echo esc_html(ucwords(str_replace('_', ' ', $status))); ?></button></form><?php endforeach; ?></div><?php endif; ?>
        <div class="obbp-panel"><h2><?php esc_html_e('Order Items', 'obydullah-b2b-portal'); ?></h2><table class="widefat striped"><thead><tr><th>SKU</th><th><?php esc_html_e('Product', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Quantity', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Unit price', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Total', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php foreach ($items as $item) : ?><tr><td><?php echo esc_html($item['sku']); ?></td><td><?php echo esc_html($item['name']); ?></td><td><?php echo esc_html($item['quantity'] . ' ' . $item['unit']); ?></td><td class="obbp-num"><?php echo esc_html(number_format_i18n((float) $item['unit_price'], 2)); ?></td><td class="obbp-num"><?php echo esc_html(number_format_i18n((float) $item['line_total'], 2)); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
        <?php
    }

    public function activity()
    {
        $this->cap('obbp_view_activity');
        $this->verify_navigation_nonce();
        $company_id = absint($_GET['company_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
        $object_type = sanitize_key(wp_unslash($_GET['object_type'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
        $action = sanitize_key(wp_unslash($_GET['action'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
        $actor_type = sanitize_key(wp_unslash($_GET['actor_type'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, nonce checked centrally
        $rows = OBBP_Repository::activity([
            'limit' => 300,
            'company_id' => $company_id,
            'object_type' => $object_type,
            'action' => $action,
            'actor_type' => $actor_type,
            'search' => $search,
        ]);
        $filtered = (bool) ($company_id || $object_type || $action || $actor_type || $search);
        $companies = OBBP_Repository::companies_list(['limit' => 500]);
        ?>
        <div class="wrap obbp-admin">
            <h1><?php esc_html_e('Activity Log', 'obydullah-b2b-portal'); ?></h1>
            <form method="get" class="obbp-filters">
                <input type="hidden" name="page" value="obbp-activity">
                <select name="company_id">
                    <option value=""><?php esc_html_e('All companies', 'obydullah-b2b-portal'); ?></option>
                    <?php foreach ($companies as $company) : ?><option value="<?php echo esc_attr($company['id']); ?>" <?php selected($company_id, (int) $company['id']); ?>><?php echo esc_html($company['name']); ?></option><?php endforeach; ?>
                </select>
                <?php $this->facet_select('object_type', $object_type, OBBP_Repository::activity_facets('object_type'), __('All object types', 'obydullah-b2b-portal')); ?>
                <?php $this->facet_select('action', $action, OBBP_Repository::activity_facets('action'), __('All actions', 'obydullah-b2b-portal')); ?>
                <?php $this->facet_select('actor_type', $actor_type, OBBP_Repository::activity_facets('actor_type'), __('All actors', 'obydullah-b2b-portal')); ?>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search message or user', 'obydullah-b2b-portal'); ?>">
                <button class="button"><?php esc_html_e('Filter', 'obydullah-b2b-portal'); ?></button>
                <?php if ($filtered) : ?><a class="button-link" href="<?php echo esc_url($this->url('obbp-activity')); ?>"><?php esc_html_e('Reset', 'obydullah-b2b-portal'); ?></a><?php endif; ?>
            </form>
            <?php $this->activity_table($rows); ?>
        </div>
        <?php
    }

    private function facet_select($name, $selected, $values, $all_label)
    {
        printf('<select name="%s"><option value="">%s</option>', esc_attr($name), esc_html($all_label));
        foreach ($values as $value) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($selected, $value, false),
                esc_html($this->facet_label($value))
            );
        }
        echo '</select>';
    }

    private function facet_label($value)
    {
        $acronyms = ['rfq' => __('RFQ', 'obydullah-b2b-portal'), 'po' => __('PO', 'obydullah-b2b-portal'), 'csv' => __('CSV', 'obydullah-b2b-portal')];
        return $acronyms[$value] ?? ucwords(str_replace('_', ' ', $value));
    }

    public function transfer()
    {
        $this->cap('obbp_import_csv');
        $this->verify_navigation_nonce();
        OBBP_CSV::render_transfer_page();
    }

    public function settings()
    {
        $this->cap('obbp_manage_settings');
        $this->verify_navigation_nonce();
        $settings = wp_parse_args((array) get_option(OBBP_OPTION_SETTINGS, []), ['portal_page_id' => 0, 'default_country' => 'BD', 'default_currency' => 'BDT', 'email_from_name' => get_bloginfo('name'), 'email_enabled' => 1, 'delete_data_on_uninstall' => 0]);
        ?>
        <div class="wrap obbp-admin"><h1><?php esc_html_e('B2B Portal Settings', 'obydullah-b2b-portal'); ?></h1><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_action')); ?>"><input type="hidden" name="obbp_do" value="save_settings"><?php wp_nonce_field(OBBP_NONCE_ADMIN, 'obbp_nonce'); ?><table class="form-table"><tbody><tr><th><label for="obbp_portal_page"><?php esc_html_e('Portal page', 'obydullah-b2b-portal'); ?></label></th><td><select id="obbp_portal_page" name="portal_page_id"><?php echo wp_kses_post(wp_dropdown_pages(['selected' => $settings['portal_page_id'], 'show_option_none' => __('Select a page', 'obydullah-b2b-portal'), 'echo' => 0])); ?></select><p class="description"><?php esc_html_e('The selected page should contain the [obbp_portal] shortcode.', 'obydullah-b2b-portal'); ?></p></td></tr><?php $this->text_field('default_country', __('Default country', 'obydullah-b2b-portal'), $settings['default_country']); ?><?php $this->text_field('default_currency', __('Default currency', 'obydullah-b2b-portal'), $settings['default_currency']); ?><?php $this->text_field('email_from_name', __('Email sender name', 'obydullah-b2b-portal'), $settings['email_from_name']); ?><tr><th><?php esc_html_e('Email', 'obydullah-b2b-portal'); ?></th><td><label><input type="checkbox" name="email_enabled" value="1" <?php checked($settings['email_enabled'], 1); ?>> <?php esc_html_e('Enable portal emails', 'obydullah-b2b-portal'); ?></label></td></tr><tr><th><?php esc_html_e('Uninstall', 'obydullah-b2b-portal'); ?></th><td><label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked($settings['delete_data_on_uninstall'], 1); ?>> <?php esc_html_e('Delete all plugin data on uninstall', 'obydullah-b2b-portal'); ?></label></td></tr></tbody></table><?php submit_button(__('Save Settings', 'obydullah-b2b-portal')); ?></form><hr><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_action')); ?>"><input type="hidden" name="obbp_do" value="resync_roles"><?php wp_nonce_field(OBBP_NONCE_ADMIN, 'obbp_nonce'); ?><button class="button"><?php esc_html_e('Synchronize Roles and Capabilities', 'obydullah-b2b-portal'); ?></button></form></div>
        <?php
    }

    private function save_company()
    {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['obbp_nonce'] ?? '')), OBBP_NONCE_ADMIN)) {
            return new WP_Error('wp_bad_nonce', __('Security check failed.', 'obydullah-b2b-portal'));
        }
        if (!current_user_can('obbp_manage_companies')) {
            return new WP_Error('obbp_forbidden', __('You are not allowed to perform this action.', 'obydullah-b2b-portal'));
        }
        $id = absint(wp_unslash($_POST['id'] ?? 0));
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!$name || !is_email($email)) {
            return new WP_Error('obbp_company_invalid', __('Company name and a valid email are required.', 'obydullah-b2b-portal'));
        }
        global $wpdb;
        $duplicate = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE email = %s AND id != %d AND deleted_at IS NULL LIMIT 1', OBBP_Repository::table('companies'), $email, $id));
        if ($duplicate) {
            return new WP_Error('obbp_company_duplicate', __('Another active company uses this email.', 'obydullah-b2b-portal'));
        }
        $settings = get_option(OBBP_OPTION_SETTINGS, []);
        $price_list_id = absint(wp_unslash($_POST['price_list_id'] ?? 0));
        $currency = strtoupper(sanitize_text_field(wp_unslash($_POST['currency'] ?? '')));
        $price_list = $price_list_id ? OBBP_Repository::row('price_lists', $price_list_id) : null;
        if ($price_list_id && (!$price_list || empty($price_list['is_active']))) {
            return new WP_Error('obbp_price_list_invalid', __('Choose an active price list.', 'obydullah-b2b-portal'));
        }
        if (!$currency && $price_list && $price_list['currency']) {
            $currency = strtoupper($price_list['currency']);
        }
        if (!$currency) {
            $currency = strtoupper((string) ($settings['default_currency'] ?? 'BDT'));
        }
        if (!preg_match('/^[A-Z0-9]{3,10}$/', $currency)) {
            return new WP_Error('obbp_currency_invalid', __('Enter a valid currency code.', 'obydullah-b2b-portal'));
        }
        if ($price_list && $price_list['currency'] && strtoupper($price_list['currency']) !== $currency) {
            return new WP_Error('obbp_currency_mismatch', __('The company currency must match the selected price list currency.', 'obydullah-b2b-portal'));
        }
        $data = [
            'name' => $name,
            'slug' => sanitize_title($name) ?: sanitize_title($email),
            'email' => $email,
            'phone' => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'website' => esc_url_raw(wp_unslash($_POST['website'] ?? '')),
            'tax_id' => sanitize_text_field(wp_unslash($_POST['tax_id'] ?? '')),
            'address_line1' => sanitize_text_field(wp_unslash($_POST['address_line1'] ?? '')),
            'address_line2' => sanitize_text_field(wp_unslash($_POST['address_line2'] ?? '')),
            'city' => sanitize_text_field(wp_unslash($_POST['city'] ?? '')),
            'state' => sanitize_text_field(wp_unslash($_POST['state'] ?? '')),
            'postcode' => sanitize_text_field(wp_unslash($_POST['postcode'] ?? '')),
            'country' => strtoupper(substr(sanitize_text_field(wp_unslash($_POST['country'] ?? ($settings['default_country'] ?? 'BD'))), 0, 2)),
            'price_list_id' => $price_list_id ?: null,
            'currency' => $currency,
            'credit_limit' => max(0, OBBP_Repository::money(sanitize_text_field(wp_unslash($_POST['credit_limit'] ?? 0)))),
            'payment_terms' => sanitize_text_field(wp_unslash($_POST['payment_terms'] ?? '')),
            'email_enabled' => isset($_POST['email_enabled']) ? 1 : 0,
        ];
        if (!$id) {
            $slug_duplicate = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s AND deleted_at IS NULL LIMIT 1', OBBP_Repository::table('companies'), $data['slug']));
            if ($slug_duplicate) {
                $data['slug'] .= '-' . substr(md5(strtolower($email)), 0, 6);
            }
        }
        if ($id) {
            $existing = OBBP_Repository::row('companies', $id);
            if (!$existing) {
                return new WP_Error('obbp_company_missing', __('Company not found.', 'obydullah-b2b-portal'));
            }
            $duplicate_slug = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s AND id != %d LIMIT 1', OBBP_Repository::table('companies'), $data['slug'], $id));
            if ($duplicate_slug) {
                $data['slug'] .= '-' . $id;
            }
            $updated = OBBP_Repository::update('companies', $data, null, ['id' => $id]);
            if (false === $updated) {
                return new WP_Error('obbp_update_failed', __('The company could not be updated.', 'obydullah-b2b-portal'));
            }
            OBBP_Repository::flush_company_access($id);
            OBBP_Repository::log($id, 'company', $id, 'updated', 'Company details updated.');
            return __('Company updated.', 'obydullah-b2b-portal');
        }
        $data['status'] = 'pending';
        $id = OBBP_Repository::insert('companies', $data);
        if (!$id) {
            return new WP_Error('obbp_insert_failed', __('The company could not be created.', 'obydullah-b2b-portal'));
        }
        OBBP_Repository::log($id, 'company', $id, 'created', 'Company account created.');
        return ['message' => __('Company created.', 'obydullah-b2b-portal'), 'redirect' => $this->url('obbp-companies', ['view' => 'edit', 'id' => $id])];
    }

    private function save_contact()
    {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['obbp_nonce'] ?? '')), OBBP_NONCE_ADMIN)) {
            return new WP_Error('wp_bad_nonce', __('Security check failed.', 'obydullah-b2b-portal'));
        }
        if (!current_user_can('obbp_manage_contacts')) {
            return new WP_Error('obbp_forbidden', __('You are not allowed to perform this action.', 'obydullah-b2b-portal'));
        }
        global $wpdb;
        $company_id = absint(wp_unslash($_POST['company_id'] ?? 0));
        $id = absint(wp_unslash($_POST['id'] ?? 0));
        if (!OBBP_Repository::row('companies', $company_id)) {
            return new WP_Error('obbp_company_missing', __('Company not found.', 'obydullah-b2b-portal'));
        }
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $name = sanitize_text_field(wp_unslash($_POST['full_name'] ?? ''));
        if (!$name || !is_email($email)) {
            return new WP_Error('obbp_contact_invalid', __('Contact name and a valid email are required.', 'obydullah-b2b-portal'));
        }
        $contact = $id ? OBBP_Repository::row('company_contacts', $id) : null;
        if ($id && (!$contact || (int) $contact['company_id'] !== $company_id)) {
            return new WP_Error('obbp_contact_missing', __('Contact not found.', 'obydullah-b2b-portal'));
        }
        $is_primary = isset($_POST['is_primary']) ? 1 : 0;
        $is_active = isset($_POST['email_enabled']) ? 1 : 0;
        if ($is_primary && !$is_active) {
            return new WP_Error('obbp_primary_contact_required', __('A primary contact must be active.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('START TRANSACTION');
        if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('companies'), $company_id))) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_company_missing', __('Company not found.', 'obydullah-b2b-portal'));
        }
        if ($id) {
            $contact = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND company_id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('company_contacts'), $id, $company_id), ARRAY_A);
            if (!$contact) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_contact_missing', __('Contact not found.', 'obydullah-b2b-portal'));
            }
        }
        if ($contact && $contact['is_primary'] && (!$is_primary || !$is_active)) {
            $other_primary = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE company_id = %d AND id != %d AND is_primary = 1 AND status = \'active\' AND deleted_at IS NULL LIMIT 1', OBBP_Repository::table('company_contacts'), $company_id, $id));
            if (!$other_primary) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_primary_contact_required', __('Choose another active primary contact before changing this one.', 'obydullah-b2b-portal'));
            }
        }
        $user_id = absint(wp_unslash($_POST['user_id'] ?? 0));
        if ($user_id && !get_userdata($user_id)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_user_missing', __('The selected WordPress user does not exist.', 'obydullah-b2b-portal'));
        }
        if ($user_id) {
            $linked_company = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE user_id = %d AND id != %d AND deleted_at IS NULL LIMIT 1', OBBP_Repository::table('companies'), $user_id, $company_id));
            $linked_contact = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE user_id = %d AND id != %d AND deleted_at IS NULL LIMIT 1', OBBP_Repository::table('company_contacts'), $user_id, $id));
            if ($linked_company || $linked_contact) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_user_already_linked', __('That WordPress user is already linked to another company.', 'obydullah-b2b-portal'));
            }
        }
        if ($is_primary) {
            $other_primary_ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE company_id = %d AND id != %d AND is_primary = 1 AND deleted_at IS NULL', OBBP_Repository::table('company_contacts'), $company_id, $id));
            $cleared = OBBP_Repository::update_ids('company_contacts', ['is_primary' => 0], ['%d'], $other_primary_ids);
            if (false === $cleared) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_update_failed', __('The primary contact could not be updated.', 'obydullah-b2b-portal'));
            }
        }
        $data = [
            'company_id' => $company_id,
            'full_name' => $name,
            'email' => $email,
            'phone' => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'job_title' => sanitize_text_field(wp_unslash($_POST['job_title'] ?? '')),
            'user_id' => $user_id ?: null,
            'is_primary' => $is_primary,
            'can_approve_quotes' => isset($_POST['can_approve_quotes']) ? 1 : 0,
            'status' => $is_active ? 'active' : 'disabled',
        ];
        if ($id) {
            $updated = OBBP_Repository::update('company_contacts', $data, null, ['id' => $id]);
            if (false === $updated) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_update_failed', __('The contact could not be updated.', 'obydullah-b2b-portal'));
            }
        } else {
            $id = OBBP_Repository::insert('company_contacts', $data);
            if (!$id) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_insert_failed', __('The contact could not be saved.', 'obydullah-b2b-portal'));
            }
        }
        $old_user_id = $contact ? absint($contact['user_id']) : 0;
        $wpdb->query('COMMIT');
        OBBP_Repository::flush_company_access($company_id, [$old_user_id, $user_id]);
        OBBP_Repository::log($company_id, 'contact', $id, $contact ? 'updated' : 'created', 'Company contact saved.');
        return __('Contact saved.', 'obydullah-b2b-portal');
    }

    private function company_status()
    {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['obbp_nonce'] ?? '')), OBBP_NONCE_ADMIN)) {
            return new WP_Error('wp_bad_nonce', __('Security check failed.', 'obydullah-b2b-portal'));
        }
        if (!current_user_can('obbp_manage_companies')) {
            return new WP_Error('obbp_forbidden', __('You are not allowed to perform this action.', 'obydullah-b2b-portal'));
        }
        return OBBP_Workflow::set_company_status(absint(wp_unslash($_POST['id'] ?? 0)), sanitize_key(wp_unslash($_POST['status'] ?? '')), sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')));
    }

    private function save_price_list()
    {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['obbp_nonce'] ?? '')), OBBP_NONCE_ADMIN)) {
            return new WP_Error('wp_bad_nonce', __('Security check failed.', 'obydullah-b2b-portal'));
        }
        if (!current_user_can('obbp_manage_price_lists')) {
            return new WP_Error('obbp_forbidden', __('You are not allowed to perform this action.', 'obydullah-b2b-portal'));
        }
        global $wpdb;
        $id = absint(wp_unslash($_POST['id'] ?? 0));
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        if (!$name) {
            return new WP_Error('obbp_price_list_invalid', __('Price list name is required.', 'obydullah-b2b-portal'));
        }
        $existing = $id ? OBBP_Repository::row('price_lists', $id) : null;
        if ($id && !$existing) {
            return new WP_Error('obbp_price_list_missing', __('Price list not found.', 'obydullah-b2b-portal'));
        }
        $valid_from = sanitize_text_field(wp_unslash($_POST['valid_from'] ?? ''));
        $valid_to = sanitize_text_field(wp_unslash($_POST['valid_to'] ?? ''));
        foreach ([$valid_from, $valid_to] as $date) {
            if ($date) {
                $date_value = DateTime::createFromFormat('Y-m-d', $date);
                if (!$date_value || $date_value->format('Y-m-d') !== $date) {
                    return new WP_Error('obbp_price_list_date_invalid', __('Enter valid price list dates.', 'obydullah-b2b-portal'));
                }
            }
        }
        if ($valid_from && $valid_to && $valid_from > $valid_to) {
            return new WP_Error('obbp_price_list_date_invalid', __('The valid-from date must be before the valid-to date.', 'obydullah-b2b-portal'));
        }
        $currency = strtoupper(sanitize_text_field(wp_unslash($_POST['currency'] ?? '')));
        if ($currency && !preg_match('/^[A-Z0-9]{3,10}$/', $currency)) {
            return new WP_Error('obbp_currency_invalid', __('Enter a valid currency code.', 'obydullah-b2b-portal'));
        }
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if ($is_default && !$is_active) {
            return new WP_Error('obbp_price_list_invalid', __('A default price list must be active.', 'obydullah-b2b-portal'));
        }
        $slug_base = sanitize_title($name) ?: 'price-list';
        $slug = $existing ? $existing['slug'] : $slug_base . '-' . wp_generate_password(6, false, false);
        $data = [
            'name' => $name,
            'slug' => $slug,
            'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            'currency' => $currency,
            'is_default' => $is_default,
            'is_active' => $is_active,
            'valid_from' => $valid_from ?: null,
            'valid_to' => $valid_to ?: null,
        ];
        $wpdb->query('START TRANSACTION');
        if ($is_default) {
            $other_default_ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE id != %d AND is_default = 1 AND deleted_at IS NULL', OBBP_Repository::table('price_lists'), $id));
            $cleared = OBBP_Repository::update_ids('price_lists', ['is_default' => 0], ['%d'], $other_default_ids);
            if (false === $cleared) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_update_failed', __('The default price list could not be updated.', 'obydullah-b2b-portal'));
            }
        }
        if ($existing) {
            $updated = OBBP_Repository::update('price_lists', $data, null, ['id' => $id]);
            if (false === $updated) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_update_failed', __('The price list could not be updated.', 'obydullah-b2b-portal'));
            }
        } else {
            $id = OBBP_Repository::insert('price_lists', $data);
            if (!$id) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_insert_failed', __('The price list could not be saved.', 'obydullah-b2b-portal'));
            }
        }
        $wpdb->query('COMMIT');
        OBBP_Repository::flush_price_list($id);
        OBBP_Repository::log(0, 'price_list', $id, $existing ? 'updated' : 'created', 'Price list saved.');
        return ['message' => __('Price list saved.', 'obydullah-b2b-portal'), 'redirect' => $this->url('obbp-price-lists', ['view' => 'edit', 'id' => $id])];
    }

    private function save_price_items()
    {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['obbp_nonce'] ?? '')), OBBP_NONCE_ADMIN)) {
            return new WP_Error('wp_bad_nonce', __('Security check failed.', 'obydullah-b2b-portal'));
        }
        if (!current_user_can('obbp_manage_price_lists')) {
            return new WP_Error('obbp_forbidden', __('You are not allowed to perform this action.', 'obydullah-b2b-portal'));
        }
        global $wpdb;
        $list_id = absint(wp_unslash($_POST['id'] ?? 0));
        if (!OBBP_Repository::row('price_lists', $list_id)) {
            return new WP_Error('obbp_price_list_missing', __('Price list not found.', 'obydullah-b2b-portal'));
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_POST['items'] is a variable-length nested array, so no single sanitizer call can cover it; every field is sanitized individually on the next loop.
        $raw_items = isset($_POST['items']) && is_array($_POST['items']) ? wp_unslash($_POST['items']) : [];
        $items = [];
        foreach ($raw_items as $raw_item) {
            if (!is_array($raw_item)) {
                continue;
            }
            $items[] = [
                'product_id' => absint($raw_item['product_id'] ?? 0),
                'unit_price' => sanitize_text_field($raw_item['unit_price'] ?? ''),
                'min_qty' => sanitize_text_field($raw_item['min_qty'] ?? ''),
                'qty_step' => sanitize_text_field($raw_item['qty_step'] ?? ''),
                'lead_time_days' => absint($raw_item['lead_time_days'] ?? 0),
                'note' => sanitize_text_field($raw_item['note'] ?? ''),
            ];
        }
        $submitted = [];
        $seen = [];
        $wpdb->query('START TRANSACTION');
        foreach ($items as $item) {
            $product_id = $item['product_id'];
            $product = $product_id ? get_post($product_id) : null;
            if (!$product || 'obbp_product' !== $product->post_type || 'trash' === $product->post_status) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_price_item_invalid', __('A price list item refers to an invalid product.', 'obydullah-b2b-portal'));
            }
            if (isset($seen[$product_id])) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_price_item_invalid', __('A product may appear only once in a price list.', 'obydullah-b2b-portal'));
            }
            $seen[$product_id] = true;
            $raw_price = $item['unit_price'];
            $raw_min = '' === $item['min_qty'] ? 1 : $item['min_qty'];
            $raw_step = '' === $item['qty_step'] ? 1 : $item['qty_step'];
            if (!is_numeric($raw_price) || !is_finite((float) $raw_price) || (float) $raw_price < 0 || !is_numeric($raw_min) || !is_finite((float) $raw_min) || (float) $raw_min <= 0 || !is_numeric($raw_step) || !is_finite((float) $raw_step) || (float) $raw_step <= 0) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_price_item_invalid', __('Enter valid nonnegative prices, minimum quantities, and quantity steps.', 'obydullah-b2b-portal'));
            }
            $submitted[] = $product_id;
            $data = [
                'price_list_id' => $list_id,
                'product_id' => $product_id,
                'unit_price' => OBBP_Repository::money($raw_price),
                'min_qty' => OBBP_Repository::quantity($raw_min),
                'qty_step' => OBBP_Repository::quantity($raw_step),
                'lead_time_days' => $item['lead_time_days'],
                'is_active' => 1,
                'note' => $item['note'],
            ];
            $replaced = $wpdb->replace(OBBP_Repository::table('price_list_items'), $data);
            if (false === $replaced) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_price_item_failed', __('A price list item could not be saved.', 'obydullah-b2b-portal'));
            }
        }
        $submitted = array_values(array_unique(array_map('absint', $submitted)));
        if ($submitted) {
            $removed_ids = OBBP_Repository::price_list_item_ids_missing($list_id, $submitted);
            $deactivated = OBBP_Repository::update_ids('price_list_items', ['is_active' => 0], ['%d'], $removed_ids);
        } else {
            $deactivated = $wpdb->update(OBBP_Repository::table('price_list_items'), ['is_active' => 0, 'updated_at' => OBBP_Repository::now()], ['price_list_id' => $list_id], ['%d', '%s']);
        }
        if (false === $deactivated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_price_item_failed', __('The price list could not be updated.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('COMMIT');
        OBBP_Repository::flush_price_list_items($list_id);
        OBBP_Repository::log(0, 'price_list', $list_id, 'items_updated', 'Price list items updated.');
        return __('Prices saved.', 'obydullah-b2b-portal');
    }

    private function save_settings()
    {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['obbp_nonce'] ?? '')), OBBP_NONCE_ADMIN)) {
            return new WP_Error('wp_bad_nonce', __('Security check failed.', 'obydullah-b2b-portal'));
        }
        if (!current_user_can('obbp_manage_settings')) {
            return new WP_Error('obbp_forbidden', __('You are not allowed to perform this action.', 'obydullah-b2b-portal'));
        }
        $old = (array) get_option(OBBP_OPTION_SETTINGS, []);
        $country = strtoupper(sanitize_text_field(wp_unslash($_POST['default_country'] ?? 'BD')));
        $currency = strtoupper(sanitize_text_field(wp_unslash($_POST['default_currency'] ?? 'BDT')));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return new WP_Error('obbp_country_invalid', __('Enter a two-letter country code.', 'obydullah-b2b-portal'));
        }
        if (!preg_match('/^[A-Z0-9]{3,10}$/', $currency)) {
            return new WP_Error('obbp_currency_invalid', __('Enter a valid currency code.', 'obydullah-b2b-portal'));
        }
        $settings = [
            'portal_page_id' => absint(wp_unslash($_POST['portal_page_id'] ?? 0)),
            'default_country' => $country,
            'default_currency' => $currency,
            'email_from_name' => sanitize_text_field(wp_unslash($_POST['email_from_name'] ?? get_bloginfo('name'))),
            'email_enabled' => isset($_POST['email_enabled']) ? 1 : 0,
            'delete_data_on_uninstall' => isset($_POST['delete_data_on_uninstall']) ? 1 : 0,
        ];
        update_option(OBBP_OPTION_SETTINGS, $settings);
        OBBP_Repository::flush_settings();
        OBBP_Repository::log(0, 'settings', 0, 'updated', 'B2B settings updated.', ['changed' => array_keys(array_diff_assoc($settings, $old))]);
        return __('Settings saved.', 'obydullah-b2b-portal');
    }

    private function quote_items_table($items)
    {
        ?>
        <table class="widefat striped"><thead><tr><th>SKU</th><th><?php esc_html_e('Product', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Quantity', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Unit price', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Tax', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Total', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php foreach ($items as $item) : ?><tr><td><?php echo esc_html($item['sku']); ?></td><td><?php echo esc_html($item['name']); ?></td><td><?php echo esc_html($item['quantity'] . ' ' . $item['unit']); ?></td><td class="obbp-num"><?php echo esc_html(number_format_i18n((float) $item['unit_price'], 2)); ?></td><td class="obbp-num"><?php echo esc_html(number_format_i18n((float) $item['line_tax_total'], 2)); ?></td><td class="obbp-num"><?php echo esc_html(number_format_i18n((float) $item['line_total'] + (float) $item['line_tax_total'], 2)); ?></td></tr><?php endforeach; ?></tbody></table>
        <?php
    }

    private function activity_table($rows)
    {
        ?>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e('When', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Actor', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Object', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Action', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Message', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php if (!$rows) : ?><tr><td colspan="5"><?php esc_html_e('No activity found.', 'obydullah-b2b-portal'); ?></td></tr><?php endif; ?><?php foreach ($rows as $row) : ?><tr><td class="obbp-when"><?php echo esc_html($row['created_at']); ?></td><td><?php echo esc_html($row['user_login'] . ' (' . $row['actor_type'] . ')'); ?></td><td><?php echo esc_html($row['object_type'] . ' #' . $row['object_id']); ?></td><td><?php echo esc_html($row['action']); ?></td><td><?php echo esc_html($row['message']); ?></td></tr><?php endforeach; ?></tbody></table>
        <?php
    }

    private function text_field($name, $label, $value, $required = false, $type = 'text')
    {
        ?>
        <tr><th><label for="obbp_field_<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label></th><td><input id="obbp_field_<?php echo esc_attr($name); ?>" type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php echo $required ? 'required' : ''; ?>></td></tr>
        <?php
    }

    private function email_field($name, $label, $value, $required = false)
    {
        ?>
        <tr><th><label for="obbp_field_<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label></th><td><input id="obbp_field_<?php echo esc_attr($name); ?>" type="email" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php echo $required ? 'required' : ''; ?>></td></tr>
        <?php
    }

    private function number_field($name, $label, $value, $step)
    {
        ?>
        <tr><th><label for="obbp_field_<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label></th><td><input id="obbp_field_<?php echo esc_attr($name); ?>" type="number" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" min="0" step="<?php echo esc_attr($step); ?>"></td></tr>
        <?php
    }

    private function badge($status, $type)
    {
        $labels = OBBP_Repository::status_labels($type);
        $label = $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
        printf('<span class="obbp-status obbp-status-%s">%s</span>', esc_attr($status), esc_html($label));
    }

    private function flag($value)
    {
        if ($value) {
            return '<span class="dashicons dashicons-yes obbp-flag obbp-flag-yes"></span><span class="screen-reader-text">' . esc_html__('Yes', 'obydullah-b2b-portal') . '</span>';
        }

        return '<span class="obbp-flag obbp-flag-no" aria-hidden="true">&mdash;</span>';
    }

    private function back_link($view, $label)
    {
        printf(
            '<p class="obbp-back"><span class="dashicons dashicons-arrow-left" aria-hidden="true"></span><a href="%s">%s</a></p>',
            esc_url($this->url($view)),
            esc_html($label)
        );
    }

    private function cap($capability)
    {
        if (!current_user_can($capability)) {
            wp_die(esc_html__('You are not allowed to view this page.', 'obydullah-b2b-portal'));
        }
    }

    private function missing()
    {
        echo '<div class="wrap"><h1>' . esc_html__('Record not found.', 'obydullah-b2b-portal') . '</h1></div>';
    }

    private function url($page, $args = [])
    {
        return wp_nonce_url(add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php')), OBBP_NONCE_ADMIN);
    }
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
