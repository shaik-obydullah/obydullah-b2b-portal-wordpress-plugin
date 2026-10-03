<?php
if (!defined('ABSPATH')) {
    exit;
}

final class OBBP_Portal
{
    public function __construct()
    {
        add_shortcode('obbp_portal', [$this, 'shortcode']);
        add_shortcode('obbp_catalog', [$this, 'catalog_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('template_redirect', [$this, 'print_redirect']);
        add_action('admin_post_obbp_portal', [$this, 'handle_post']);
        add_action('admin_post_obbp_portal_download', [$this, 'download']);
    }

    public function assets()
    {
        global $post;
        if (!$post || (!has_shortcode($post->post_content, 'obbp_portal') && !has_shortcode($post->post_content, 'obbp_catalog'))) {
            return;
        }
        wp_enqueue_style('obbp-portal', OBBP_URL . 'assets/css/obbp-portal.css', [], OBBP_VERSION);
        wp_enqueue_script('obbp-portal', OBBP_URL . 'assets/js/obbp-portal.js', [], OBBP_VERSION, true);
        wp_localize_script('obbp-portal', 'obbpPortal', ['ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce(OBBP_NONCE_PORTAL), 'adding' => __('Adding…', 'obydullah-b2b-portal')]);
    }

    /**
     * Portal navigation parameters only select what to display.
     *
     * Every link built by OBBP_Portal::url() carries a nonce, and a present but
     * invalid nonce is rejected. A missing nonce is allowed through because the
     * portal page is also reachable directly (bookmarks, the site menu, plain
     * page URLs), and every read is already scoped to the signed-in company by
     * is_user_logged_in(), OBBP_Repository::can_access_portal() and
     * OBBP_Repository::company_for_user().
     */
    public function shortcode($attributes)
    {
        $nav_nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if ($nav_nonce && !wp_verify_nonce($nav_nonce, OBBP_NONCE_PORTAL)) {
            wp_die(esc_html__('Security check failed.', 'obydullah-b2b-portal'));
        }
        $attributes = shortcode_atts(['default_view' => 'dashboard', 'title' => __('B2B Portal', 'obydullah-b2b-portal')], $attributes, 'obbp_portal');
        if (!is_user_logged_in()) {
            return $this->login_view();
        }
        if (!OBBP_Repository::can_access_portal()) {
            return $this->denied_view();
        }
        $view = sanitize_key(wp_unslash($_GET['obbp_view'] ?? $attributes['default_view']));
        $allowed = ['dashboard', 'catalog', 'rfqs', 'rfq', 'quotes', 'quote', 'orders', 'order'];
        if (!in_array($view, $allowed, true)) {
            $view = sanitize_key($attributes['default_view']);
            if (!in_array($view, $allowed, true)) {
                $view = 'dashboard';
            }
        }
        $company = OBBP_Repository::company_for_user(get_current_user_id());
        if ('quote' === $view && !empty($_GET['obbp_print'])) {
            return $this->print_quote(absint(wp_unslash($_GET['obbp_id'] ?? 0)), $company);
        }
        ob_start();
        ?>
        <div class="obbp-portal-shell">
            <header class="obbp-portal-header"><div><span class="obbp-eyebrow"><?php esc_html_e('Customer workspace', 'obydullah-b2b-portal'); ?></span><h1><?php echo esc_html($attributes['title']); ?></h1></div><div class="obbp-company-chip"><span><?php esc_html_e('Signed in for', 'obydullah-b2b-portal'); ?></span><strong><?php echo esc_html($company['name']); ?></strong></div></header>
            <nav class="obbp-portal-nav" aria-label="<?php esc_attr_e('Portal navigation', 'obydullah-b2b-portal'); ?>">
                <?php $this->nav_link('dashboard', __('Overview', 'obydullah-b2b-portal')); ?><?php $this->nav_link('catalog', __('Catalog', 'obydullah-b2b-portal')); ?><?php $this->nav_link('rfqs', __('RFQs', 'obydullah-b2b-portal')); ?><?php $this->nav_link('quotes', __('Quotes', 'obydullah-b2b-portal')); ?><?php $this->nav_link('orders', __('Purchase Orders', 'obydullah-b2b-portal')); ?>
            </nav>
            <?php $this->messages(); ?>
            <main class="obbp-portal-main">
                <?php
                $id = absint(wp_unslash($_GET['obbp_id'] ?? 0));
                if ('catalog' === $view) {
                    $this->catalog($company);
                } elseif ('rfqs' === $view) {
                    $this->rfq_list($company);
                } elseif ('rfq' === $view) {
                    $this->rfq_detail($id, $company);
                } elseif ('quotes' === $view) {
                    $this->quote_list($company);
                } elseif ('quote' === $view) {
                    $this->quote_detail($id, $company);
                } elseif ('orders' === $view) {
                    $this->order_list($company);
                } elseif ('order' === $view) {
                    $this->order_detail($id, $company);
                } else {
                    $this->dashboard($company);
                }
                ?>
            </main>
        </div>
        <?php
        return ob_get_clean();
    }

    public function print_redirect()
    {
        $nav_nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if ($nav_nonce && !wp_verify_nonce($nav_nonce, OBBP_NONCE_PORTAL)) {
            wp_die(esc_html__('Security check failed.', 'obydullah-b2b-portal'));
        }
        if (empty($_GET['obbp_print']) || 'quote' !== sanitize_key(wp_unslash($_GET['obbp_view'] ?? ''))) {
            return;
        }
        if (!is_user_logged_in() || !OBBP_Repository::can_access_portal()) {
            wp_die(esc_html__('Access denied.', 'obydullah-b2b-portal'));
        }
        $post = get_post();
        if (!$post || !has_shortcode($post->post_content, 'obbp_portal')) {
            return;
        }
        $html = $this->print_quote(absint(wp_unslash($_GET['obbp_id'] ?? 0)), OBBP_Repository::company_for_user(get_current_user_id()));
        status_header(200);
        nocache_headers();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- print_quote() returns a complete standalone print document in which every interpolated value is escaped at source; wp_kses_post() would strip the required <style>, <script> and <!doctype> markup.
        echo $html;
        exit;
    }

    public function catalog_shortcode()
    {
        $nav_nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if ($nav_nonce && !wp_verify_nonce($nav_nonce, OBBP_NONCE_PORTAL)) {
            wp_die(esc_html__('Security check failed.', 'obydullah-b2b-portal'));
        }
        if (!is_user_logged_in()) {
            return $this->login_view();
        }
        if (!OBBP_Repository::can_access_portal()) {
            return $this->denied_view();
        }
        ob_start();
        $this->catalog(OBBP_Repository::company_for_user(get_current_user_id()));
        return ob_get_clean();
    }

    public function handle_post()
    {
        $nonce = sanitize_text_field(wp_unslash($_POST['obbp_nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, OBBP_NONCE_PORTAL)) {
            wp_die(esc_html__('Security check failed.', 'obydullah-b2b-portal'));
        }
        $action = sanitize_key(wp_unslash($_POST['obbp_do'] ?? ''));
        $capabilities = [
            'add_item' => 'obbp_submit_rfq',
            'save_rfq' => 'obbp_submit_rfq',
            'submit_rfq' => 'obbp_submit_rfq',
            'update_item' => 'obbp_submit_rfq',
            'remove_item' => 'obbp_submit_rfq',
            'accept_quote' => 'obbp_accept_quotes',
            'reject_quote' => 'obbp_accept_quotes',
            'acknowledge_po' => 'obbp_view_orders',
            'reorder' => 'obbp_reorder',
        ];
        if (!isset($capabilities[$action]) || !is_user_logged_in() || !current_user_can($capabilities[$action]) || !OBBP_Repository::can_access_portal()) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'obydullah-b2b-portal'));
        }
        $result = $this->run_action($action);
        $redirect = $this->redirect_for($action);
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('obbp_error', rawurlencode($result->get_error_message()), $redirect));
            exit;
        }
        $message = is_string($result) ? $result : __('Changes saved.', 'obydullah-b2b-portal');
        if (is_array($result)) {
            $message = $result['message'] ?? $message;
            $redirect = $result['redirect'] ?? $redirect;
        }
        wp_safe_redirect(add_query_arg('obbp_message', rawurlencode($message), $redirect));
        exit;
    }

    private static function quantities($raw)
    {
        $clean = [];
        if (!is_array($raw)) {
            return $clean;
        }
        foreach ($raw as $item_id => $quantity) {
            if (is_array($quantity) || is_object($quantity)) {
                continue;
            }
            // Keep non-numeric values intact so OBBP_Workflow::accept_quote() can reject them with its own message.
            $clean[absint($item_id)] = sanitize_text_field((string) $quantity);
        }
        return $clean;
    }

    private function run_action($action)
    {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['obbp_nonce'] ?? '')), OBBP_NONCE_PORTAL)) {
            return new WP_Error('obbp_bad_nonce', __('Security check failed.', 'obydullah-b2b-portal'));
        }
        $user_id = get_current_user_id();
        switch ($action) {
            case 'add_item':
                $result = OBBP_Workflow::add_rfq_item(absint(wp_unslash($_POST['product_id'] ?? 0)), sanitize_text_field(wp_unslash($_POST['quantity'] ?? '')), sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')), $user_id);
                if (is_wp_error($result)) {
                    return $result;
                }
                return ['message' => __('Item added to your RFQ.', 'obydullah-b2b-portal'), 'redirect' => $this->url('rfq', ['id' => $result['rfq_id']])];
            case 'save_rfq':
                return OBBP_Workflow::save_rfq(absint(wp_unslash($_POST['rfq_id'] ?? 0)), $user_id, wp_unslash($_POST));
            case 'submit_rfq':
                return OBBP_Workflow::submit_rfq(absint(wp_unslash($_POST['rfq_id'] ?? 0)), $user_id);
            case 'update_item':
                return OBBP_Workflow::update_rfq_item(absint(wp_unslash($_POST['item_id'] ?? 0)), $user_id, sanitize_text_field(wp_unslash($_POST['quantity'] ?? '')), sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')));
            case 'remove_item':
                return OBBP_Workflow::remove_rfq_item(absint(wp_unslash($_POST['item_id'] ?? 0)), $user_id);
            case 'accept_quote':
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_POST['accepted_quantities'] is a variable-length nested array, so no single sanitizer call can cover it; self::quantities() rejects array/object leaves and sanitizes every scalar below.
                $accepted = isset($_POST['accepted_quantities']) && is_array($_POST['accepted_quantities']) ? wp_unslash($_POST['accepted_quantities']) : [];
                return OBBP_Workflow::accept_quote(absint(wp_unslash($_POST['quote_id'] ?? 0)), $user_id, self::quantities($accepted));
            case 'reject_quote':
                return OBBP_Workflow::reject_quote(absint(wp_unslash($_POST['quote_id'] ?? 0)), $user_id, sanitize_textarea_field(wp_unslash($_POST['reason'] ?? '')));
            case 'acknowledge_po':
                return OBBP_Workflow::acknowledge_po(absint(wp_unslash($_POST['po_id'] ?? 0)), $user_id);
            case 'reorder':
                $id = OBBP_Workflow::create_reorder(absint(wp_unslash($_POST['rfq_id'] ?? 0)), $user_id);
                return is_wp_error($id) ? $id : ['message' => __('Items copied to a new draft RFQ.', 'obydullah-b2b-portal'), 'redirect' => $this->url('rfq', ['id' => $id])];
        }
        return new WP_Error('obbp_unknown_action', __('Unknown action.', 'obydullah-b2b-portal'));
    }

    private function redirect_for($action)
    {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['obbp_nonce'] ?? '')), OBBP_NONCE_PORTAL)) {
            return $this->url('dashboard');
        }
        $id = absint(wp_unslash($_POST['rfq_id'] ?? $_POST['quote_id'] ?? $_POST['po_id'] ?? $_POST['item_id'] ?? 0));
        $map = [
            'add_item' => 'catalog',
            'save_rfq' => 'rfq',
            'submit_rfq' => 'rfq',
            'update_item' => 'rfq',
            'remove_item' => 'rfq',
            'accept_quote' => 'quote',
            'reject_quote' => 'quote',
            'acknowledge_po' => 'order',
            'reorder' => 'rfq',
        ];
        return $this->url($map[$action], $id ? ['id' => $id] : []);
    }

    public function download()
    {
        if (!is_user_logged_in() || !OBBP_Repository::can_access_portal()) {
            wp_die(esc_html__('Access denied.', 'obydullah-b2b-portal'));
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['obbp_nonce'] ?? '')), 'obbp_portal_download')) {
            wp_die(esc_html__('Security check failed.', 'obydullah-b2b-portal'));
        }
        $type = sanitize_key(wp_unslash($_GET['type'] ?? ''));
        $id = absint(wp_unslash($_GET['id'] ?? 0));
        if ('quote' !== $type) {
            wp_die(esc_html__('Invalid download.', 'obydullah-b2b-portal'));
        }
        $quote = OBBP_Workflow::company_quote($id, get_current_user_id());
        if (!$quote) {
            wp_die(esc_html__('Quote not found.', 'obydullah-b2b-portal'));
        }
        global $wpdb;
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE quote_id = %d AND deleted_at IS NULL ORDER BY line_sort ASC, id ASC', OBBP_Repository::table('quote_items'), $id), ARRAY_A);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . sanitize_file_name($quote['quote_number'] . '.csv'));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming directly to the response body; there is no filesystem path to manage.
        $output = fopen('php://output', 'w');
        fputcsv($output, ['SKU', 'Item', 'Quantity', 'Unit', 'Unit price', 'Tax', 'Line total'], ',', '"', '');
        foreach ($items as $item) {
            $values = [$item['sku'], $item['name'], $item['quantity'], $item['unit'], $item['unit_price'], $item['line_tax_total'], $item['line_total']];
            $values = array_map([$this, 'safe_csv_value'], $values);
            fputcsv($output, $values, ',', '"', '');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the php://output stream opened above.
        fclose($output);
        OBBP_Repository::log($quote['company_id'], 'quote', $id, 'downloaded', 'Quote CSV downloaded.');
        exit;
    }

    private function login_view()
    {
        $redirect = $this->current_url();
        ob_start();
        ?>
        <div class="obbp-login"><div class="obbp-login-card"><span class="obbp-eyebrow"><?php esc_html_e('Secure customer access', 'obydullah-b2b-portal'); ?></span><h1><?php esc_html_e('Sign in to your B2B portal', 'obydullah-b2b-portal'); ?></h1><p><?php esc_html_e('Browse products, request quotations, and track purchase orders.', 'obydullah-b2b-portal'); ?></p><?php if ($this->has_message()) { $this->messages(); } ?><form action="<?php echo esc_url(wp_login_url()); ?>" method="post"><label><?php esc_html_e('Email or username', 'obydullah-b2b-portal'); ?><input type="text" name="log" autocomplete="username" required></label><label><?php esc_html_e('Password', 'obydullah-b2b-portal'); ?><input type="password" name="pwd" autocomplete="current-password" required></label><input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect); ?>"><button type="submit"><?php esc_html_e('Sign In', 'obydullah-b2b-portal'); ?></button></form><a href="<?php echo esc_url(wp_lostpassword_url($redirect)); ?>"><?php esc_html_e('Forgot password?', 'obydullah-b2b-portal'); ?></a></div></div>
        <?php
        return ob_get_clean();
    }

    private function denied_view()
    {
        ob_start();
        ?>
        <div class="obbp-login"><div class="obbp-login-card"><span class="obbp-eyebrow"><?php esc_html_e('Account access', 'obydullah-b2b-portal'); ?></span><h1><?php esc_html_e('Portal access is not available', 'obydullah-b2b-portal'); ?></h1><p><?php esc_html_e('Your account must be linked to an approved company. Contact the supplier if you need access.', 'obydullah-b2b-portal'); ?></p><a class="obbp-button" href="<?php echo esc_url(wp_logout_url($this->portal_base_url())); ?>"><?php esc_html_e('Sign Out', 'obydullah-b2b-portal'); ?></a></div></div>
        <?php
        return ob_get_clean();
    }

    private function dashboard($company)
    {
        global $wpdb;
        $counts = [
            'rfq' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE company_id = %d AND deleted_at IS NULL", OBBP_Repository::table('rfqs'), $company['id'])),
            'quote' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE company_id = %d AND deleted_at IS NULL", OBBP_Repository::table('quotes'), $company['id'])),
            'order' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE company_id = %d AND status != 'draft' AND deleted_at IS NULL", OBBP_Repository::table('purchase_orders'), $company['id'])),
        ];
        $rfqs = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE company_id = %d AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 5', OBBP_Repository::table('rfqs'), $company['id']), ARRAY_A);
        ?>
        <section class="obbp-hero"><div><span class="obbp-eyebrow"><?php esc_html_e('Procurement workspace', 'obydullah-b2b-portal'); ?></span><h2><?php esc_html_e('Good to see you', 'obydullah-b2b-portal'); ?></h2><p><?php esc_html_e('Create a request, compare a quote, and follow fulfillment from one place.', 'obydullah-b2b-portal'); ?></p><a class="obbp-button" href="<?php echo esc_url($this->url('catalog')); ?>"><?php esc_html_e('Start a New RFQ', 'obydullah-b2b-portal'); ?></a></div><div class="obbp-hero-art" aria-hidden="true"><span></span><span></span><span></span></div></section>
        <div class="obbp-metric-grid"><a class="obbp-metric-card" href="<?php echo esc_url($this->url('rfqs')); ?>"><strong><?php echo esc_html($counts['rfq']); ?></strong><span><?php esc_html_e('Total RFQs', 'obydullah-b2b-portal'); ?></span></a><a class="obbp-metric-card" href="<?php echo esc_url($this->url('quotes')); ?>"><strong><?php echo esc_html($counts['quote']); ?></strong><span><?php esc_html_e('Quotes', 'obydullah-b2b-portal'); ?></span></a><a class="obbp-metric-card" href="<?php echo esc_url($this->url('orders')); ?>"><strong><?php echo esc_html($counts['order']); ?></strong><span><?php esc_html_e('Purchase orders', 'obydullah-b2b-portal'); ?></span></a></div>
        <div class="obbp-card"><div class="obbp-card-heading"><h2><?php esc_html_e('Recent requests', 'obydullah-b2b-portal'); ?></h2><a href="<?php echo esc_url($this->url('rfqs')); ?>"><?php esc_html_e('View all', 'obydullah-b2b-portal'); ?></a></div><?php $this->rfq_rows($rfqs); ?></div>
        <?php
    }

    private function catalog($company)
    {
        $nav_nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if ($nav_nonce && !wp_verify_nonce($nav_nonce, OBBP_NONCE_PORTAL)) {
            wp_die(esc_html__('Security check failed.', 'obydullah-b2b-portal'));
        }
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $paged = max(1, absint(wp_unslash($_GET['paged'] ?? 1)));
        $query = OBBP_Repository::products($company, $search, $paged);
        ?>
        <div class="obbp-page-heading"><div><span class="obbp-eyebrow"><?php esc_html_e('Approved catalog', 'obydullah-b2b-portal'); ?></span><h2><?php esc_html_e('Products & Services', 'obydullah-b2b-portal'); ?></h2><p><?php esc_html_e('Add one or many items to a draft RFQ.', 'obydullah-b2b-portal'); ?></p></div><form method="get" class="obbp-search"><input type="hidden" name="obbp_view" value="catalog"><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search catalog', 'obydullah-b2b-portal'); ?>"><button><?php esc_html_e('Search', 'obydullah-b2b-portal'); ?></button></form></div>
        <?php if ($query->have_posts()) : ?><div class="obbp-product-grid"><?php while ($query->have_posts()) : $query->the_post(); $product = get_post(); $pricing = OBBP_Repository::price_for_product($product->ID, $company); if (empty($pricing['currency_ok'])) { continue; } $attributes = OBBP_Catalog::attributes($product->ID); ?><article class="obbp-product-card"><?php if (has_post_thumbnail($product)) : ?><div class="obbp-product-image"><?php echo get_the_post_thumbnail($product, 'medium', ['loading' => 'lazy']); ?></div><?php endif; ?><div class="obbp-product-body"><div class="obbp-product-topline"><span><?php echo esc_html(get_post_meta($product->ID, '_obbp_sku', true)); ?></span><?php $this->status_badge(get_post_meta($product->ID, '_obbp_availability', true), 'availability'); ?></div><h3><?php echo esc_html($product->post_title); ?></h3><p><?php echo esc_html($product->post_excerpt); ?></p><?php if ($attributes) : ?><dl class="obbp-attributes"><?php foreach (array_slice($attributes, 0, 4) as $attribute) : ?><div><dt><?php echo esc_html($attribute['label']); ?></dt><dd><?php echo esc_html($attribute['value']); ?></dd></div><?php endforeach; ?></dl><?php endif; ?><div class="obbp-product-price"><strong><?php echo esc_html($this->money($pricing['price'], $pricing['currency'] ?? '')); ?></strong><span><?php esc_html_e('per', 'obydullah-b2b-portal'); ?> <?php echo esc_html(get_post_meta($product->ID, '_obbp_unit', true) ?: 'pcs'); ?></span></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_portal')); ?>" class="obbp-add-form"><input type="hidden" name="obbp_do" value="add_item"><input type="hidden" name="product_id" value="<?php echo esc_attr($product->ID); ?>"><?php wp_nonce_field(OBBP_NONCE_PORTAL, 'obbp_nonce'); ?><label><span><?php esc_html_e('Quantity', 'obydullah-b2b-portal'); ?></span><input type="number" name="quantity" min="<?php echo esc_attr($pricing['min_qty']); ?>" step="<?php echo esc_attr($pricing['step']); ?>" value="<?php echo esc_attr($pricing['min_qty']); ?>" required></label><label><span><?php esc_html_e('Note', 'obydullah-b2b-portal'); ?></span><input name="note" maxlength="500" placeholder="<?php esc_attr_e('Optional specification', 'obydullah-b2b-portal'); ?>"></label><button type="submit"><?php esc_html_e('Add to RFQ', 'obydullah-b2b-portal'); ?></button></form></div></article><?php endwhile; ?></div><?php $this->pagination($query, 'catalog', ['s' => $search]); ?><?php else : ?><div class="obbp-empty"><h3><?php esc_html_e('No catalog items found', 'obydullah-b2b-portal'); ?></h3><p><?php esc_html_e('Try a different search or ask the supplier to add a product.', 'obydullah-b2b-portal'); ?></p></div><?php endif; ?>
        <?php $this->pagination($query, 'catalog', ['s' => $search]); wp_reset_postdata();
    }

    private function rfq_list($company)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE company_id = %d AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 200', OBBP_Repository::table('rfqs'), $company['id']), ARRAY_A);
        ?>
        <div class="obbp-page-heading"><div><span class="obbp-eyebrow"><?php esc_html_e('Requests', 'obydullah-b2b-portal'); ?></span><h2><?php esc_html_e('Request for Quotations', 'obydullah-b2b-portal'); ?></h2><p><?php esc_html_e('Track drafts, submissions, and quote outcomes.', 'obydullah-b2b-portal'); ?></p></div><a class="obbp-button" href="<?php echo esc_url($this->url('catalog')); ?>"><?php esc_html_e('New RFQ', 'obydullah-b2b-portal'); ?></a></div><div class="obbp-card"><?php $this->rfq_rows($rows); ?></div>
        <?php
    }

    private function rfq_rows($rows)
    {
        ?>
        <div class="obbp-table-wrap"><table class="obbp-table"><thead><tr><th><?php esc_html_e('RFQ', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Items', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Delivery', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Status', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Updated', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php if (!$rows) : ?><tr><td colspan="5" class="obbp-table-empty"><?php esc_html_e('No requests found.', 'obydullah-b2b-portal'); ?></td></tr><?php endif; ?><?php foreach ($rows as $row) : ?><tr><td><a href="<?php echo esc_url($this->url('rfq', ['id' => $row['id']])); ?>"><strong><?php echo esc_html($row['rfq_number']); ?></strong></a></td><td><?php echo esc_html($row['item_count']); ?></td><td><?php echo esc_html($row['requested_delivery_date'] ?: '—'); ?></td><td><?php $this->status_badge($row['status'], 'status'); ?></td><td><?php echo esc_html($row['updated_at']); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php
    }

    private function rfq_detail($id, $company)
    {
        global $wpdb;
        $rfq = OBBP_Workflow::company_rfq($id, get_current_user_id());
        if (!$rfq || (int) $rfq['company_id'] !== (int) $company['id']) {
            $this->not_found();
            return;
        }
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE rfq_id = %d AND deleted_at IS NULL ORDER BY line_sort ASC, id ASC', OBBP_Repository::table('rfq_items'), $id), ARRAY_A);
        $editable = 'draft' === $rfq['status'];
        ?>
        <div class="obbp-page-heading"><div><span class="obbp-eyebrow"><?php echo esc_html($rfq['rfq_number']); ?></span><h2><?php esc_html_e('Request Details', 'obydullah-b2b-portal'); ?></h2></div><?php $this->status_badge($rfq['status'], 'status'); ?></div>
        <?php if ($editable) : ?><div class="obbp-card"><div class="obbp-card-heading"><h2><?php esc_html_e('Request Information', 'obydullah-b2b-portal'); ?></h2></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_portal')); ?>" class="obbp-stack-form"><input type="hidden" name="obbp_do" value="save_rfq"><input type="hidden" name="rfq_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field(OBBP_NONCE_PORTAL, 'obbp_nonce'); ?><label><?php esc_html_e('Subject', 'obydullah-b2b-portal'); ?><input name="subject" maxlength="191" value="<?php echo esc_attr($rfq['subject']); ?>"></label><label><?php esc_html_e('Requested delivery date', 'obydullah-b2b-portal'); ?><input type="date" name="requested_delivery_date" value="<?php echo esc_attr($rfq['requested_delivery_date']); ?>"></label><label><?php esc_html_e('Notes', 'obydullah-b2b-portal'); ?><textarea name="notes" rows="4"><?php echo esc_textarea($rfq['notes']); ?></textarea></label><button><?php esc_html_e('Save Request', 'obydullah-b2b-portal'); ?></button></form></div><?php endif; ?>
        <div class="obbp-card"><div class="obbp-card-heading"><h2><?php esc_html_e('Requested Items', 'obydullah-b2b-portal'); ?></h2><?php if ($editable) : ?><a href="<?php echo esc_url($this->url('catalog')); ?>"><?php esc_html_e('Add more items', 'obydullah-b2b-portal'); ?></a><?php endif; ?></div><?php if (!$items) : ?><div class="obbp-empty"><p><?php esc_html_e('No items in this request.', 'obydullah-b2b-portal'); ?></p></div><?php else : ?><div class="obbp-rfq-items"><?php foreach ($items as $item) : ?><article class="obbp-rfq-item"><div><span class="obbp-sku"><?php echo esc_html($item['sku']); ?></span><h3><?php echo esc_html($item['name']); ?></h3><?php if ($item['note']) : ?><p><?php echo esc_html($item['note']); ?></p><?php endif; ?></div><?php if ($editable) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_portal')); ?>" class="obbp-item-form"><input type="hidden" name="obbp_do" value="update_item"><input type="hidden" name="item_id" value="<?php echo esc_attr($item['id']); ?>"><input type="hidden" name="rfq_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field(OBBP_NONCE_PORTAL, 'obbp_nonce'); ?><label><?php esc_html_e('Quantity', 'obydullah-b2b-portal'); ?><input type="number" step="0.0001" min="0.0001" name="quantity" value="<?php echo esc_attr($item['quantity']); ?>" required></label><label><?php esc_html_e('Note', 'obydullah-b2b-portal'); ?><input name="note" value="<?php echo esc_attr($item['note']); ?>"></label><button class="obbp-button-small"><?php esc_html_e('Update', 'obydullah-b2b-portal'); ?></button><button class="obbp-button-small obbp-danger" name="obbp_do" value="remove_item" onclick="return confirm('<?php echo esc_js(__('Remove this item?', 'obydullah-b2b-portal')); ?>');"><?php esc_html_e('Remove', 'obydullah-b2b-portal'); ?></button></form><?php else : ?><strong><?php echo esc_html($item['quantity'] . ' ' . $item['unit']); ?></strong><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?></div>
        <?php if ('draft' === $rfq['status']) : ?><div class="obbp-action-bar"><span><?php esc_html_e('Ready to request a price?', 'obydullah-b2b-portal'); ?></span><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_portal')); ?>"><input type="hidden" name="obbp_do" value="submit_rfq"><input type="hidden" name="rfq_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field(OBBP_NONCE_PORTAL, 'obbp_nonce'); ?><button class="obbp-button"><?php esc_html_e('Submit RFQ', 'obydullah-b2b-portal'); ?></button></form></div><?php endif; ?>
        <?php if (in_array($rfq['status'], ['quoted', 'ordered', 'closed'], true)) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_portal')); ?>"><input type="hidden" name="obbp_do" value="reorder"><input type="hidden" name="rfq_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field(OBBP_NONCE_PORTAL, 'obbp_nonce'); ?><button class="obbp-secondary-button"><?php esc_html_e('Reorder These Items', 'obydullah-b2b-portal'); ?></button></form><?php endif; ?>
        <?php
    }

    private function quote_list($company)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT q.*, r.rfq_number FROM %i q LEFT JOIN %i r ON r.id = q.rfq_id WHERE q.company_id = %d AND q.status NOT IN (\'draft\', \'superseded\') AND q.deleted_at IS NULL ORDER BY q.created_at DESC LIMIT 200', OBBP_Repository::table('quotes'), OBBP_Repository::table('rfqs'), $company['id']), ARRAY_A);
        ?>
        <div class="obbp-page-heading"><div><span class="obbp-eyebrow"><?php esc_html_e('Offers', 'obydullah-b2b-portal'); ?></span><h2><?php esc_html_e('Quotations', 'obydullah-b2b-portal'); ?></h2><p><?php esc_html_e('Review pricing, print, download, and respond.', 'obydullah-b2b-portal'); ?></p></div></div><div class="obbp-card"><div class="obbp-table-wrap"><table class="obbp-table"><thead><tr><th><?php esc_html_e('Quote', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('RFQ', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Total', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Valid until', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Status', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php if (!$rows) : ?><tr><td colspan="5" class="obbp-table-empty"><?php esc_html_e('No quotations found.', 'obydullah-b2b-portal'); ?></td></tr><?php endif; ?><?php foreach ($rows as $row) : ?><tr><td><a href="<?php echo esc_url($this->url('quote', ['id' => $row['id']])); ?>"><strong><?php echo esc_html($row['quote_number']); ?></strong></a></td><td><?php echo esc_html($row['rfq_number'] ?: '—'); ?></td><td><?php echo esc_html($row['currency'] . ' ' . number_format_i18n((float) $row['grand_total'], 2)); ?></td><td><?php echo esc_html($row['valid_until']); ?></td><td><?php $this->status_badge($row['status'], 'status'); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
        <?php
    }

    private function quote_detail($id, $company)
    {
        global $wpdb;
        $quote = OBBP_Workflow::company_quote($id, get_current_user_id());
        if (!$quote || (int) $quote['company_id'] !== (int) $company['id']) {
            $this->not_found();
            return;
        }
        OBBP_Workflow::mark_quote_viewed($id, get_current_user_id());
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE quote_id = %d AND deleted_at IS NULL ORDER BY line_sort ASC, id ASC', OBBP_Repository::table('quote_items'), $id), ARRAY_A);
        $can_respond = in_array($quote['status'], ['sent', 'viewed'], true) && (!$quote['valid_until'] || $quote['valid_until'] >= current_time('Y-m-d')) && OBBP_Workflow::can_approve_quote($id, get_current_user_id());
        ?>
        <div class="obbp-page-heading"><div><span class="obbp-eyebrow"><?php echo esc_html($quote['quote_number']); ?></span><h2><?php esc_html_e('Quotation', 'obydullah-b2b-portal'); ?></h2><p><?php esc_html_e('Valid until', 'obydullah-b2b-portal'); ?> <?php echo esc_html($quote['valid_until']); ?></p></div><div class="obbp-heading-actions"><?php $this->status_badge($quote['status'], 'status'); ?><a class="obbp-secondary-button" href="<?php echo esc_url($this->url('quote', ['id' => $id, 'obbp_print' => 1])); ?>" target="_blank"><?php esc_html_e('Print', 'obydullah-b2b-portal'); ?></a><a class="obbp-secondary-button" href="<?php echo esc_url(add_query_arg(['action' => 'obbp_portal_download', 'type' => 'quote', 'id' => $id, 'obbp_nonce' => wp_create_nonce('obbp_portal_download')], admin_url('admin-post.php'))); ?>"><?php esc_html_e('Download CSV', 'obydullah-b2b-portal'); ?></a></div></div>
        <div class="obbp-card obbp-quote-sheet"><div class="obbp-quote-meta"><div><span><?php esc_html_e('Prepared for', 'obydullah-b2b-portal'); ?></span><strong><?php echo esc_html($company['name']); ?></strong><p><?php echo nl2br(esc_html(OBBP_Workflow::formatted_address($company))); ?></p></div><div><span><?php esc_html_e('Quote total', 'obydullah-b2b-portal'); ?></span><strong class="obbp-total"><?php echo esc_html($quote['currency'] . ' ' . number_format_i18n((float) $quote['grand_total'], 2)); ?></strong><p><?php echo esc_html($quote['payment_terms']); ?></p></div></div><div class="obbp-table-wrap"><table class="obbp-table obbp-quote-table"><thead><tr><th><?php esc_html_e('Item', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Quantity', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Unit price', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Tax', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Total', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php foreach ($items as $item) : ?><tr><td><span class="obbp-sku"><?php echo esc_html($item['sku']); ?></span><strong><?php echo esc_html($item['name']); ?></strong><?php if ($item['note']) : ?><small><?php echo esc_html($item['note']); ?></small><?php endif; ?></td><td><?php if ($can_respond) : ?><input form="obbp-accept-quote" type="number" name="accepted_quantities[<?php echo esc_attr($item['id']); ?>]" min="0" max="<?php echo esc_attr($item['quantity']); ?>" step="0.0001" value="<?php echo esc_attr($item['quantity']); ?>"><small><?php echo esc_html($item['unit']); ?></small><?php else : ?><?php echo esc_html($item['quantity'] . ' ' . $item['unit']); ?><?php endif; ?></td><td><?php echo esc_html(number_format_i18n((float) $item['unit_price'], 2)); ?></td><td><?php echo esc_html(number_format_i18n((float) $item['line_tax_total'], 2)); ?></td><td><?php echo esc_html(number_format_i18n((float) $item['line_total'] + (float) $item['line_tax_total'], 2)); ?></td></tr><?php endforeach; ?></tbody><tfoot><tr><td colspan="4"><?php esc_html_e('Subtotal', 'obydullah-b2b-portal'); ?></td><td><?php echo esc_html(number_format_i18n((float) $quote['subtotal'], 2)); ?></td></tr><tr><td colspan="4"><?php esc_html_e('Discount', 'obydullah-b2b-portal'); ?></td><td>−<?php echo esc_html(number_format_i18n((float) $quote['discount_total'], 2)); ?></td></tr><tr><td colspan="4"><?php esc_html_e('Tax', 'obydullah-b2b-portal'); ?></td><td><?php echo esc_html(number_format_i18n((float) $quote['tax_total'], 2)); ?></td></tr><tr><td colspan="4"><strong><?php esc_html_e('Total', 'obydullah-b2b-portal'); ?></strong></td><td><strong><?php echo esc_html(number_format_i18n((float) $quote['grand_total'], 2)); ?></strong></td></tr></tfoot></table></div><?php if ($quote['notes'] || $quote['delivery_terms']) : ?><div class="obbp-quote-notes"><p><?php echo nl2br(esc_html($quote['notes'])); ?></p><strong><?php esc_html_e('Delivery', 'obydullah-b2b-portal'); ?>:</strong> <?php echo esc_html($quote['delivery_terms']); ?></div><?php endif; ?></div>
        <?php if ($can_respond) : ?><div class="obbp-response-grid"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_portal')); ?>" class="obbp-card" id="obbp-accept-quote"><input type="hidden" name="obbp_do" value="accept_quote"><input type="hidden" name="quote_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field(OBBP_NONCE_PORTAL, 'obbp_nonce'); ?><span class="obbp-eyebrow"><?php esc_html_e('Accept quotation', 'obydullah-b2b-portal'); ?></span><h2><?php esc_html_e('Confirm the quantities you want to order', 'obydullah-b2b-portal'); ?></h2><p><?php esc_html_e('Set a quantity to zero to decline that item. Acceptance creates a draft purchase order.', 'obydullah-b2b-portal'); ?></p><button class="obbp-button"><?php esc_html_e('Accept & Create Order', 'obydullah-b2b-portal'); ?></button></form><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_portal')); ?>" class="obbp-card"><input type="hidden" name="obbp_do" value="reject_quote"><input type="hidden" name="quote_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field(OBBP_NONCE_PORTAL, 'obbp_nonce'); ?><label><?php esc_html_e('Reason for rejection', 'obydullah-b2b-portal'); ?><textarea name="reason" rows="3" required></textarea></label><button class="obbp-secondary-button obbp-danger"><?php esc_html_e('Reject Quotation', 'obydullah-b2b-portal'); ?></button></form></div><?php elseif (!OBBP_Workflow::can_approve_quote($id, get_current_user_id()) && in_array($quote['status'], ['sent', 'viewed'], true) && (!$quote['valid_until'] || $quote['valid_until'] >= current_time('Y-m-d'))) : ?><div class="obbp-notice"><?php esc_html_e('This quotation requires a contact with quote approval permission.', 'obydullah-b2b-portal'); ?></div><?php endif; ?>
        <?php
    }

    private function order_list($company)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT p.*, q.quote_number FROM %i p LEFT JOIN %i q ON q.id = p.quote_id WHERE p.company_id = %d AND p.status != \'draft\' AND p.deleted_at IS NULL ORDER BY p.created_at DESC LIMIT 200', OBBP_Repository::table('purchase_orders'), OBBP_Repository::table('quotes'), $company['id']), ARRAY_A);
        ?>
        <div class="obbp-page-heading"><div><span class="obbp-eyebrow"><?php esc_html_e('Fulfillment', 'obydullah-b2b-portal'); ?></span><h2><?php esc_html_e('Purchase Order History', 'obydullah-b2b-portal'); ?></h2><p><?php esc_html_e('Review issued orders and fulfillment status.', 'obydullah-b2b-portal'); ?></p></div></div><div class="obbp-card"><div class="obbp-table-wrap"><table class="obbp-table"><thead><tr><th><?php esc_html_e('PO', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Quote', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Total', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Promised ship', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Status', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php if (!$rows) : ?><tr><td colspan="5" class="obbp-table-empty"><?php esc_html_e('No purchase orders found.', 'obydullah-b2b-portal'); ?></td></tr><?php endif; ?><?php foreach ($rows as $row) : ?><tr><td><a href="<?php echo esc_url($this->url('order', ['id' => $row['id']])); ?>"><strong><?php echo esc_html($row['po_number']); ?></strong></a></td><td><?php echo esc_html($row['quote_number'] ?: '—'); ?></td><td><?php echo esc_html($row['currency'] . ' ' . number_format_i18n((float) $row['grand_total'], 2)); ?></td><td><?php echo esc_html($row['promised_ship_date'] ?: '—'); ?></td><td><?php $this->status_badge($row['status'], 'status'); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
        <?php
    }

    private function order_detail($id, $company)
    {
        global $wpdb;
        $po = OBBP_Workflow::company_po($id, get_current_user_id());
        if (!$po || (int) $po['company_id'] !== (int) $company['id']) {
            $this->not_found();
            return;
        }
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE purchase_order_id = %d AND deleted_at IS NULL ORDER BY line_sort ASC, id ASC', OBBP_Repository::table('purchase_order_items'), $id), ARRAY_A);
        ?>
        <div class="obbp-page-heading"><div><span class="obbp-eyebrow"><?php echo esc_html($po['po_number']); ?></span><h2><?php esc_html_e('Purchase Order', 'obydullah-b2b-portal'); ?></h2></div><?php $this->status_badge($po['status'], 'status'); ?></div><div class="obbp-card"><div class="obbp-quote-meta"><div><span><?php esc_html_e('Ship to', 'obydullah-b2b-portal'); ?></span><strong><?php echo esc_html($po['ship_to_name']); ?></strong><p><?php echo nl2br(esc_html($po['ship_to_address'])); ?></p></div><div><span><?php esc_html_e('Order total', 'obydullah-b2b-portal'); ?></span><strong class="obbp-total"><?php echo esc_html($po['currency'] . ' ' . number_format_i18n((float) $po['grand_total'], 2)); ?></strong></div></div><div class="obbp-table-wrap"><table class="obbp-table"><thead><tr><th><?php esc_html_e('Item', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Quantity', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Unit price', 'obydullah-b2b-portal'); ?></th><th><?php esc_html_e('Total', 'obydullah-b2b-portal'); ?></th></tr></thead><tbody><?php foreach ($items as $item) : ?><tr><td><span class="obbp-sku"><?php echo esc_html($item['sku']); ?></span><strong><?php echo esc_html($item['name']); ?></strong></td><td><?php echo esc_html($item['quantity'] . ' ' . $item['unit']); ?></td><td><?php echo esc_html(number_format_i18n((float) $item['unit_price'], 2)); ?></td><td><?php echo esc_html(number_format_i18n((float) $item['line_total'], 2)); ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php if ('issued' === $po['status']) : ?><div class="obbp-action-bar"><span><?php esc_html_e('Please confirm receipt of this purchase order.', 'obydullah-b2b-portal'); ?></span><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_portal')); ?>"><input type="hidden" name="obbp_do" value="acknowledge_po"><input type="hidden" name="po_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field(OBBP_NONCE_PORTAL, 'obbp_nonce'); ?><button class="obbp-button"><?php esc_html_e('Acknowledge Order', 'obydullah-b2b-portal'); ?></button></form></div><?php endif; ?>
        <?php
    }

    private function print_quote($id, $company)
    {
        global $wpdb;
        $quote = OBBP_Workflow::company_quote($id, get_current_user_id());
        if (!$quote || (int) $quote['company_id'] !== (int) $company['id']) {
            $this->not_found();
            return '';
        }
        OBBP_Workflow::mark_quote_viewed($id, get_current_user_id());
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE quote_id = %d AND deleted_at IS NULL ORDER BY line_sort ASC, id ASC', OBBP_Repository::table('quote_items'), $id), ARRAY_A);
        ob_start();
        ?>
        <!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html($quote['quote_number']); ?></title><style>body{font:14px/1.5 Arial,sans-serif;color:#172033;margin:40px}header{display:flex;justify-content:space-between;border-bottom:3px solid #0d6b55;padding-bottom:24px;margin-bottom:30px}h1{margin:0;color:#0d6b55}.total{font-size:24px;font-weight:700}table{width:100%;border-collapse:collapse}th,td{padding:12px 8px;border-bottom:1px solid #d9e1e8;text-align:left}th{background:#f3f7f6}.meta{display:flex;justify-content:space-between;margin:20px 0}.meta strong{display:block}.notes{margin-top:30px;white-space:pre-wrap}@media print{body{margin:20px}.no-print{display:none}}</style></head><body><header><div><h1><?php echo esc_html(get_bloginfo('name')); ?></h1><p><?php echo esc_html($quote['quote_number']); ?></p></div><div class="total"><?php echo esc_html($quote['currency'] . ' ' . number_format_i18n((float) $quote['grand_total'], 2)); ?></div></header><div class="meta"><div><span>Prepared for</span><strong><?php echo esc_html($company['name']); ?></strong><span><?php echo nl2br(esc_html(OBBP_Workflow::formatted_address($company))); ?></span></div><div><span>Issue date</span><strong><?php echo esc_html($quote['issue_date']); ?></strong><span>Valid until <?php echo esc_html($quote['valid_until']); ?></span></div></div><table><thead><tr><th>SKU</th><th>Item</th><th>Quantity</th><th>Unit price</th><th>Tax</th><th>Total</th></tr></thead><tbody><?php foreach ($items as $item) : ?><tr><td><?php echo esc_html($item['sku']); ?></td><td><?php echo esc_html($item['name']); ?></td><td><?php echo esc_html($item['quantity'] . ' ' . $item['unit']); ?></td><td><?php echo esc_html(number_format_i18n((float) $item['unit_price'], 2)); ?></td><td><?php echo esc_html(number_format_i18n((float) $item['line_tax_total'], 2)); ?></td><td><?php echo esc_html(number_format_i18n((float) $item['line_total'] + (float) $item['line_tax_total'], 2)); ?></td></tr><?php endforeach; ?></tbody><tfoot><tr><td colspan="5"><strong>Total</strong></td><td><strong><?php echo esc_html($quote['currency'] . ' ' . number_format_i18n((float) $quote['grand_total'], 2)); ?></strong></td></tr></tfoot></table><div class="notes"><?php echo esc_html($quote['notes']); ?></div><p><?php echo esc_html__('Thank you for your business.', 'obydullah-b2b-portal'); ?></p><p class="no-print"><button onclick="window.print()">Print</button> <a href="<?php echo esc_url($this->url('quote', ['id' => $id])); ?>">Return to portal</a></p><script>window.addEventListener('load',function(){window.print()});</script></body></html>
        <?php
        return ob_get_clean();
    }

    private function status_badge($status, $type)
    {
        $labels = OBBP_Repository::status_labels($type);
        $label = $labels[$status] ?? ($type === 'availability' ? (OBBP_Catalog::availability_options()[$status] ?? $status) : ucfirst(str_replace('_', ' ', $status)));
        printf('<span class="obbp-status obbp-status-%s">%s</span>', esc_attr($status), esc_html($label));
    }

    private function nav_link($view, $label)
    {
        $nav_nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if ($nav_nonce && !wp_verify_nonce($nav_nonce, OBBP_NONCE_PORTAL)) {
            wp_die(esc_html__('Security check failed.', 'obydullah-b2b-portal'));
        }
        $current = sanitize_key(wp_unslash($_GET['obbp_view'] ?? 'dashboard')) === $view;
        printf('<a class="%s" href="%s">%s</a>', esc_attr($current ? 'active' : ''), esc_url($this->url($view)), esc_html($label));
    }

    private function messages()
    {
        $nav_nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if ($nav_nonce && !wp_verify_nonce($nav_nonce, OBBP_NONCE_PORTAL)) {
            wp_die(esc_html__('Security check failed.', 'obydullah-b2b-portal'));
        }
        $message = sanitize_text_field(wp_unslash($_GET['obbp_message'] ?? ''));
        if ($message) {
            printf('<div class="obbp-notice obbp-notice-success">%s</div>', esc_html($message));
        }
        $error = sanitize_text_field(wp_unslash($_GET['obbp_error'] ?? ''));
        if ($error) {
            printf('<div class="obbp-notice obbp-notice-error">%s</div>', esc_html($error));
        }
    }

    private function has_message()
    {
        $nav_nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if ($nav_nonce && !wp_verify_nonce($nav_nonce, OBBP_NONCE_PORTAL)) {
            wp_die(esc_html__('Security check failed.', 'obydullah-b2b-portal'));
        }
        return !empty($_GET['obbp_message']) || !empty($_GET['obbp_error']);
    }

    private function pagination($query, $view, $args = [])
    {
        $nav_nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if ($nav_nonce && !wp_verify_nonce($nav_nonce, OBBP_NONCE_PORTAL)) {
            wp_die(esc_html__('Security check failed.', 'obydullah-b2b-portal'));
        }
        if ($query->max_num_pages < 2) {
            return;
        }
        echo '<nav class="obbp-pagination">';
        echo wp_kses_post(paginate_links(['base' => wp_nonce_url(add_query_arg(array_merge(['obbp_view' => $view, 'paged' => '%#%'], $args), $this->portal_base_url()), OBBP_NONCE_PORTAL), 'format' => '', 'current' => max(1, absint(wp_unslash($_GET['paged'] ?? 1))), 'type' => 'list', 'prev_text' => __('Previous', 'obydullah-b2b-portal'), 'next_text' => __('Next', 'obydullah-b2b-portal')]));
        echo '</nav>';
    }

    private function safe_csv_value($value)
    {
        if (is_string($value) && preg_match('/^\s*[=+\-@]/', $value)) {
            return "'" . ltrim($value);
        }
        return $value;
    }

    private function money($value, $currency = '')
    {
        $currency = $currency ?: get_option(OBBP_OPTION_SETTINGS, [])['default_currency'] ?? '';
        if (!$currency) {
            $settings = get_option(OBBP_OPTION_SETTINGS, []);
            $currency = $settings['default_currency'] ?? 'BDT';
        }
        return strtoupper($currency) . ' ' . number_format_i18n($value, 2);
    }

    private function not_found()
    {
        echo '<div class="obbp-empty"><h2>' . esc_html__('Record not found.', 'obydullah-b2b-portal') . '</h2><a href="' . esc_url($this->url('dashboard')) . '">' . esc_html__('Return to overview', 'obydullah-b2b-portal') . '</a></div>';
    }

    public function portal_base_url()
    {
        $settings = get_option(OBBP_OPTION_SETTINGS, []);
        $page_id = absint($settings['portal_page_id'] ?? 0);
        $url = $page_id ? get_permalink($page_id) : home_url('/');
        return $url ?: home_url('/');
    }

    private function url($view, $args = [])
    {
        return wp_nonce_url(
            add_query_arg(array_merge(['obbp_view' => sanitize_key($view)], $args), $this->portal_base_url()),
            OBBP_NONCE_PORTAL
        );
    }

    private function current_url()
    {
        $nav_nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if ($nav_nonce && !wp_verify_nonce($nav_nonce, OBBP_NONCE_PORTAL)) {
            wp_die(esc_html__('Security check failed.', 'obydullah-b2b-portal'));
        }
        $query = array_filter([
            'obbp_view' => sanitize_key(wp_unslash($_GET['obbp_view'] ?? 'dashboard')),
            'obbp_id' => absint(wp_unslash($_GET['obbp_id'] ?? 0)),
        ]);
        return add_query_arg($query, $this->portal_base_url());
    }
}
