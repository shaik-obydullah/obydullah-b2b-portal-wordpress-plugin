<?php
if (!defined('ABSPATH')) {
    exit;
}

final class OBBP_Catalog
{
    public static function init()
    {
        add_action('init', [__CLASS__, 'register']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes']);
        add_action('save_post_obbp_product', [__CLASS__, 'save_product'], 10, 2);
    }

    public static function register()
    {
        register_post_type('obbp_product', [
            'labels' => [
                'name' => __('Products & Services', 'obydullah-b2b-portal'),
                'singular_name' => __('Product or Service', 'obydullah-b2b-portal'),
                'add_new_item' => __('Add New', 'obydullah-b2b-portal'),
                'edit_item' => __('Edit', 'obydullah-b2b-portal'),
                'search_items' => __('Search Catalog', 'obydullah-b2b-portal'),
                'not_found' => __('No catalog items found.', 'obydullah-b2b-portal'),
            ],
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'exclude_from_search' => true,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'revisions'],
            'taxonomies' => ['obbp_product_cat', 'obbp_product_tag'],
            'capability_type' => ['obbp_product', 'obbp_products'],
            'map_meta_cap' => true,
            'menu_icon' => 'dashicons-cart',
        ]);

        register_taxonomy('obbp_product_cat', ['obbp_product'], [
            'labels' => ['name' => __('Product Categories', 'obydullah-b2b-portal'), 'singular_name' => __('Product Category', 'obydullah-b2b-portal')],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'hierarchical' => true,
            'capabilities' => [
                'manage_terms' => 'obbp_manage_product_terms',
                'edit_terms' => 'obbp_edit_product_terms',
                'delete_terms' => 'obbp_delete_product_terms',
                'assign_terms' => 'obbp_assign_product_terms',
            ],
        ]);

        register_taxonomy('obbp_product_tag', ['obbp_product'], [
            'labels' => ['name' => __('Product Tags', 'obydullah-b2b-portal'), 'singular_name' => __('Product Tag', 'obydullah-b2b-portal')],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'hierarchical' => false,
            'capabilities' => [
                'manage_terms' => 'obbp_manage_product_terms',
                'edit_terms' => 'obbp_edit_product_terms',
                'delete_terms' => 'obbp_delete_product_terms',
                'assign_terms' => 'obbp_assign_product_terms',
            ],
        ]);

        $auth = function ($allowed, $meta_key, $post_id) {
            unset($allowed, $meta_key);
            return current_user_can('edit_post', $post_id);
        };
        $definitions = [
            '_obbp_sku' => 'string',
            '_obbp_unit' => 'string',
            '_obbp_price' => 'number',
            '_obbp_currency' => 'string',
            '_obbp_min_order_qty' => 'number',
            '_obbp_qty_step' => 'number',
            '_obbp_availability' => 'string',
            '_obbp_lead_time_days' => 'integer',
            '_obbp_tax_rate' => 'number',
            '_obbp_is_service' => 'boolean',
            '_obbp_portal_visible' => 'boolean',
            '_obbp_attributes' => 'array',
        ];
        foreach ($definitions as $key => $type) {
            register_post_meta('obbp_product', $key, [
                'single' => true,
                'type' => $type,
                'show_in_rest' => false,
                'auth_callback' => $auth,
            ]);
        }
    }

    public static function add_meta_boxes()
    {
        add_meta_box('obbp-product-details', __('Catalog Details', 'obydullah-b2b-portal'), [__CLASS__, 'render_meta_box'], 'obbp_product', 'normal', 'high');
    }

    public static function render_meta_box($post)
    {
        wp_nonce_field('obbp_save_product', 'obbp_product_nonce');
        $sku = get_post_meta($post->ID, '_obbp_sku', true);
        $unit = get_post_meta($post->ID, '_obbp_unit', true) ?: 'pcs';
        $price = get_post_meta($post->ID, '_obbp_price', true);
        $currency = get_post_meta($post->ID, '_obbp_currency', true);
        $min_qty = get_post_meta($post->ID, '_obbp_min_order_qty', true);
        $step = get_post_meta($post->ID, '_obbp_qty_step', true);
        $availability = get_post_meta($post->ID, '_obbp_availability', true) ?: 'in_stock';
        $lead = get_post_meta($post->ID, '_obbp_lead_time_days', true);
        $tax = get_post_meta($post->ID, '_obbp_tax_rate', true);
        $visible = metadata_exists('post', $post->ID, '_obbp_portal_visible') ? (int) get_post_meta($post->ID, '_obbp_portal_visible', true) : 1;
        $service = (int) get_post_meta($post->ID, '_obbp_is_service', true);
        $attributes = get_post_meta($post->ID, '_obbp_attributes', true);
        $attribute_text = '';
        if (is_array($attributes)) {
            foreach ($attributes as $attribute) {
                if (!empty($attribute['label']) && isset($attribute['value'])) {
                    $attribute_text .= $attribute['label'] . ': ' . $attribute['value'] . "\n";
                }
            }
        }
        ?>
        <div class="obbp-product-fields">
            <p><label for="obbp_sku"><?php esc_html_e('SKU', 'obydullah-b2b-portal'); ?></label><br><input class="regular-text" id="obbp_sku" name="obbp_sku" value="<?php echo esc_attr($sku); ?>" maxlength="64"></p>
            <p><label for="obbp_unit"><?php esc_html_e('Unit', 'obydullah-b2b-portal'); ?></label><br><input id="obbp_unit" name="obbp_unit" value="<?php echo esc_attr($unit); ?>" maxlength="20" placeholder="pcs"></p>
            <p><label for="obbp_price"><?php esc_html_e('Base price', 'obydullah-b2b-portal'); ?></label><br><input id="obbp_price" name="obbp_price" type="number" step="0.0001" min="0" value="<?php echo esc_attr($price); ?>"></p>
            <p><label for="obbp_currency"><?php esc_html_e('Price currency', 'obydullah-b2b-portal'); ?></label><br><input id="obbp_currency" name="obbp_currency" value="<?php echo esc_attr($currency); ?>" maxlength="10" placeholder="<?php echo esc_attr(self::default_currency()); ?>"></p>
            <p><label for="obbp_min_qty"><?php esc_html_e('Minimum quantity', 'obydullah-b2b-portal'); ?></label><br><input id="obbp_min_qty" name="obbp_min_qty" type="number" step="0.0001" min="0" value="<?php echo esc_attr($min_qty); ?>"></p>
            <p><label for="obbp_step"><?php esc_html_e('Quantity step', 'obydullah-b2b-portal'); ?></label><br><input id="obbp_step" name="obbp_step" type="number" step="0.0001" min="0" value="<?php echo esc_attr($step); ?>"></p>
            <p><label for="obbp_availability"><?php esc_html_e('Availability', 'obydullah-b2b-portal'); ?></label><br>
                <select id="obbp_availability" name="obbp_availability">
                    <?php foreach (self::availability_options() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($availability, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p><label for="obbp_lead"><?php esc_html_e('Lead time in days', 'obydullah-b2b-portal'); ?></label><br><input id="obbp_lead" name="obbp_lead" type="number" min="0" value="<?php echo esc_attr($lead); ?>"></p>
            <p><label for="obbp_tax"><?php esc_html_e('Tax rate (%)', 'obydullah-b2b-portal'); ?></label><br><input id="obbp_tax" name="obbp_tax" type="number" step="0.0001" min="0" value="<?php echo esc_attr($tax); ?>"></p>
            <p><label><input type="checkbox" name="obbp_portal_visible" value="1" <?php checked($visible, 1); ?>> <?php esc_html_e('Visible in customer portal', 'obydullah-b2b-portal'); ?></label></p>
            <p><label><input type="checkbox" name="obbp_is_service" value="1" <?php checked($service, 1); ?>> <?php esc_html_e('This is a service', 'obydullah-b2b-portal'); ?></label></p>
            <p><label for="obbp_attributes"><?php esc_html_e('Attributes', 'obydullah-b2b-portal'); ?></label><br><span class="description"><?php esc_html_e('One attribute per line in Label: Value format.', 'obydullah-b2b-portal'); ?></span><br><textarea id="obbp_attributes" name="obbp_attributes" rows="5" cols="60"><?php echo esc_textarea($attribute_text); ?></textarea></p>
        </div>
        <?php
    }

    public static function save_product($post_id, $post)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id) || !isset($_POST['obbp_product_nonce'])) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash($_POST['obbp_product_nonce']));
        if (!wp_verify_nonce($nonce, 'obbp_save_product') || !current_user_can('edit_post', $post_id)) {
            return;
        }
        $sku = strtoupper(sanitize_text_field(wp_unslash($_POST['obbp_sku'] ?? '')));
        if ($sku) {
            $matches = get_posts([
                'post_type' => 'obbp_product',
                'post_status' => ['publish', 'draft', 'private', 'pending'],
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_query' => [['key' => '_obbp_sku', 'value' => $sku]],
                'exclude' => [$post_id],
            ]);
            if ($matches) {
                $sku = '';
            }
        }
        $currency = strtoupper(sanitize_text_field(wp_unslash($_POST['obbp_currency'] ?? '')));
        if ($currency && !preg_match('/^[A-Z0-9]{3,10}$/', $currency)) {
            $currency = '';
        }
        $availability = sanitize_key(wp_unslash($_POST['obbp_availability'] ?? ''));
        if (!array_key_exists($availability, self::availability_options())) {
            $availability = 'in_stock';
        }
        $values = [
            '_obbp_sku' => $sku,
            '_obbp_unit' => sanitize_text_field(wp_unslash($_POST['obbp_unit'] ?? 'pcs')) ?: 'pcs',
            '_obbp_price' => max(0, OBBP_Repository::money(sanitize_text_field(wp_unslash($_POST['obbp_price'] ?? '0')))),
            '_obbp_currency' => $currency,
            '_obbp_min_order_qty' => max(0, OBBP_Repository::quantity(sanitize_text_field(wp_unslash($_POST['obbp_min_qty'] ?? '0')))),
            '_obbp_qty_step' => max(0, OBBP_Repository::quantity(sanitize_text_field(wp_unslash($_POST['obbp_step'] ?? '0')))),
            '_obbp_availability' => $availability,
            '_obbp_lead_time_days' => absint(wp_unslash($_POST['obbp_lead'] ?? 0)),
            '_obbp_tax_rate' => min(100, max(0, OBBP_Repository::quantity(sanitize_text_field(wp_unslash($_POST['obbp_tax'] ?? '0'))))),
            '_obbp_portal_visible' => isset($_POST['obbp_portal_visible']) ? 1 : 0,
            '_obbp_is_service' => isset($_POST['obbp_is_service']) ? 1 : 0,
            '_obbp_attributes' => self::parse_attributes(sanitize_textarea_field(wp_unslash($_POST['obbp_attributes'] ?? ''))),
        ];
        foreach ($values as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }
        OBBP_Repository::flush_product_prices($post_id);
        unset($post);
    }

    public static function attributes($product_id)
    {
        $attributes = get_post_meta($product_id, '_obbp_attributes', true);
        return is_array($attributes) ? $attributes : [];
    }

    public static function availability_options()
    {
        return [
            'in_stock' => __('In stock', 'obydullah-b2b-portal'),
            'out_of_stock' => __('Out of stock', 'obydullah-b2b-portal'),
            'backorder' => __('Backorder', 'obydullah-b2b-portal'),
            'made_to_order' => __('Made to order', 'obydullah-b2b-portal'),
            'discontinued' => __('Discontinued', 'obydullah-b2b-portal'),
        ];
    }

    public static function default_currency()
    {
        $settings = get_option(OBBP_OPTION_SETTINGS, []);
        return $settings['default_currency'] ?? 'BDT';
    }

    private static function parse_attributes($text)
    {
        $attributes = [];
        foreach (preg_split('/\R/', (string) $text) as $line) {
            $parts = array_map('trim', explode(':', $line, 2));
            if (count($parts) === 2 && $parts[0] !== '') {
                $attributes[] = ['label' => sanitize_text_field($parts[0]), 'value' => sanitize_textarea_field($parts[1])];
            }
        }
        return $attributes;
    }
}
