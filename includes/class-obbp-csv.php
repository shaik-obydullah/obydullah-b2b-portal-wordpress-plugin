<?php
if (!defined('ABSPATH')) {
    exit;
}

final class OBBP_CSV
{
    public function __construct(private array $notices = [])
    {
        add_action('admin_post_obbp_import', [$this, 'handle_import']);
        add_action('admin_post_obbp_export', [$this, 'handle_export']);
    }

    public static function render_transfer_page()
    {
        ?>
        <div class="wrap obbp-admin"><h1><?php esc_html_e('CSV Import and Export', 'obydullah-b2b-portal'); ?></h1><div class="obbp-panel"><h2><?php esc_html_e('Import', 'obydullah-b2b-portal'); ?></h2><form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_import')); ?>"><input type="hidden" name="obbp_do" value="import"><?php wp_nonce_field(OBBP_NONCE_ADMIN, 'obbp_nonce'); ?><p><label><?php esc_html_e('Data type', 'obydullah-b2b-portal'); ?><select name="entity" id="obbp-import-entity"><option value="companies"><?php esc_html_e('Companies', 'obydullah-b2b-portal'); ?></option><option value="catalog"><?php esc_html_e('Catalog', 'obydullah-b2b-portal'); ?></option><option value="price_list"><?php esc_html_e('Price list items', 'obydullah-b2b-portal'); ?></option><option value="price_lists"><?php esc_html_e('Price lists', 'obydullah-b2b-portal'); ?></option></select></label></p><p><label><?php esc_html_e('Price list ID', 'obydullah-b2b-portal'); ?><input type="number" name="price_list_id" min="1" value="0"></label></p><p><label><?php esc_html_e('CSV file', 'obydullah-b2b-portal'); ?><input type="file" name="import_file" accept=".csv,text/csv" required></label></p><p><label><input type="checkbox" name="apply_status" value="1"> <?php esc_html_e('Apply company status from the CSV. This runs each change through the approval workflow, so it can provision portal logins and send status emails.', 'obydullah-b2b-portal'); ?></label></p><p><button class="button button-primary"><?php esc_html_e('Import CSV', 'obydullah-b2b-portal'); ?></button></p><p class="description"><?php esc_html_e('Company headers: name,email,phone,website,tax_id,address_line1,address_line2,city,state,postcode,country,currency,payment_terms,price_list_id,status,status_note. Catalog headers: title,sku,description,unit,price,currency,availability,min_qty,qty_step,lead_time_days,tax_rate,is_service,portal_visible,post_status. Price-list headers: name,slug,description,currency,is_default,is_active,valid_from,valid_to. Price-item headers: sku,unit_price,min_qty,qty_step,lead_time_days,is_active,note.', 'obydullah-b2b-portal'); ?></p></form></div><div class="obbp-panel"><h2><?php esc_html_e('Export', 'obydullah-b2b-portal'); ?></h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=obbp_export')); ?>"><input type="hidden" name="obbp_do" value="export"><?php wp_nonce_field(OBBP_NONCE_ADMIN, 'obbp_nonce'); ?><p><label><?php esc_html_e('Data type', 'obydullah-b2b-portal'); ?><select name="entity"><option value="companies"><?php esc_html_e('Companies', 'obydullah-b2b-portal'); ?></option><option value="catalog"><?php esc_html_e('Catalog', 'obydullah-b2b-portal'); ?></option><option value="price_lists"><?php esc_html_e('Price lists', 'obydullah-b2b-portal'); ?></option><option value="price_list_items"><?php esc_html_e('Price list items', 'obydullah-b2b-portal'); ?></option><option value="rfqs"><?php esc_html_e('RFQs', 'obydullah-b2b-portal'); ?></option><option value="quotes"><?php esc_html_e('Quote items', 'obydullah-b2b-portal'); ?></option><option value="purchase_orders"><?php esc_html_e('Purchase order items', 'obydullah-b2b-portal'); ?></option><option value="activity"><?php esc_html_e('Activity log', 'obydullah-b2b-portal'); ?></option></select></label></p><p><label><?php esc_html_e('Price list ID', 'obydullah-b2b-portal'); ?><input type="number" name="price_list_id" min="0" value="0"></label></p><button class="button button-primary"><?php esc_html_e('Download CSV', 'obydullah-b2b-portal'); ?></button></form></div></div>
        <?php
    }

    private function open_upload($tmp_name)
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reads the PHP-managed upload temp file for direct CSV streaming; WP_Filesystem does not apply to uploaded file handles.
        return fopen($tmp_name, 'r');
    }

    private function open_output()
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams the export straight to the response body; there is no filesystem path to manage.
        return fopen('php://output', 'w');
    }

    private function close_handle($handle)
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a stream opened by open_upload()/open_output().
        fclose($handle);
    }

    public function handle_import()
    {
        if (!current_user_can('obbp_import_csv')) {
            wp_die(esc_html__('Access denied.', 'obydullah-b2b-portal'));
        }
        check_admin_referer(OBBP_NONCE_ADMIN, 'obbp_nonce');
        // $_FILES is deliberately not unslashed: wp_magic_quotes() leaves it untouched. Each upload
        // metadata field is read on its own so it can be validated, instead of passing a raw nested array.
        $upload_error = isset($_FILES['import_file']['error']) ? absint($_FILES['import_file']['error']) : UPLOAD_ERR_NO_FILE;
        $tmp_name = isset($_FILES['import_file']['tmp_name']) && is_string($_FILES['import_file']['tmp_name']) ? sanitize_text_field($_FILES['import_file']['tmp_name']) : '';
        $upload_size = isset($_FILES['import_file']['size']) ? absint($_FILES['import_file']['size']) : 0;
        if (UPLOAD_ERR_OK !== $upload_error || !$tmp_name || !is_uploaded_file($tmp_name)) {
            $this->import_redirect(new WP_Error('obbp_file_missing', __('Choose a CSV file.', 'obydullah-b2b-portal')));
        }
        if ($upload_size > 5 * 1024 * 1024) {
            $this->import_redirect(new WP_Error('obbp_file_large', __('The CSV file is too large.', 'obydullah-b2b-portal')));
        }
        $entity = sanitize_key(wp_unslash($_POST['entity'] ?? ''));
        if ('price_list_items' === $entity) {
            $entity = 'price_list';
        }
        if (!in_array($entity, ['companies', 'catalog', 'price_list', 'price_lists'], true)) {
            $this->import_redirect(new WP_Error('obbp_import_type', __('Invalid import type.', 'obydullah-b2b-portal')));
        }
        $file = $this->open_upload($tmp_name);
        if (!$file) {
            $this->import_redirect(new WP_Error('obbp_file_read', __('The CSV could not be read.', 'obydullah-b2b-portal')));
        }
        $header = self::read_csv_row($file);
        if (!$header) {
            $this->close_handle($file);
            $this->import_redirect(new WP_Error('obbp_file_empty', __('The CSV is empty.', 'obydullah-b2b-portal')));
        }
        $header[0] = ltrim((string) $header[0], "\xEF\xBB\xBF");
        $header = array_map(function ($value) {
            return sanitize_key(trim($value));
        }, $header);
        if (!$header || count(array_filter($header)) !== count($header) || count(array_unique($header)) !== count($header)) {
            $this->close_handle($file);
            $this->import_redirect(new WP_Error('obbp_header_invalid', __('The CSV header is invalid.', 'obydullah-b2b-portal')));
        }
        $required_headers = [
            'companies' => ['name', 'email'],
            'catalog' => ['title', 'sku'],
            'price_list' => ['sku', 'unit_price'],
            'price_lists' => ['name', 'currency'],
        ];
        $missing_headers = array_diff($required_headers[$entity], $header);
        if ($missing_headers) {
            $this->close_handle($file);
            $this->import_redirect(new WP_Error('obbp_header_invalid', __('The CSV is missing required columns.', 'obydullah-b2b-portal')));
        }
        $count = 0;
        $errors = [];
        $this->notices = [];
        $line = 1;
        $default_list_id = absint(wp_unslash($_POST['price_list_id'] ?? 0));
        $apply_status = !empty($_POST['apply_status']);
        while (($values = self::read_csv_row($file)) !== false) {
            $line++;
            if ([] === $values || !self::row_has_content($values)) {
                continue;
            }
            $values = self::fit_csv_row($values, count($header));
            if (false === $values) {
                /* translators: %d: line number in the imported CSV file. */
                $errors[] = sprintf(__('Line %d has an invalid column count.', 'obydullah-b2b-portal'), $line);
                continue;
            }
            $row = array_combine($header, $values);
            $result = match ($entity) {
                'companies' => $this->import_company($row, $apply_status, $line),
                'catalog' => $this->import_catalog($row),
                'price_lists' => $this->import_price_list($row),
                default => $this->import_price($row, $default_list_id),
            };
            if (is_wp_error($result)) {
                /* translators: 1: line number in the imported CSV file, 2: the message recorded for that line. */
                $errors[] = sprintf(__('Line %1$d: %2$s', 'obydullah-b2b-portal'), $line, $result->get_error_message());
            } else {
                $count++;
            }
        }
        $this->close_handle($file);
        $notices = $this->notices;
        OBBP_Repository::log(0, 'import', 0, 'csv_imported', sprintf('Imported %d %s rows from CSV.', $count, $entity), ['count' => $count, 'errors' => $errors, 'notices' => $notices], $errors || $notices ? 'warning' : 'info');
        /* translators: %d: number of rows imported. */
        $message = sprintf(_n('%d row imported successfully.', '%d rows imported successfully.', $count, 'obydullah-b2b-portal'), $count);
        if ($notices) {
            /* translators: %d: number of rows that imported but need review. */
            $message .= ' ' . sprintf(_n('%d row needs follow-up.', '%d rows need follow-up.', count($notices), 'obydullah-b2b-portal'), count($notices));
        }
        if ($errors) {
            /* translators: %d: number of rows that were rejected. */
            $message .= ' ' . sprintf(_n('%d row skipped.', '%d rows skipped.', count($errors), 'obydullah-b2b-portal'), count($errors));
        }
        wp_safe_redirect(add_query_arg(['page' => 'obbp-transfer', 'obbp_message' => rawurlencode($message)], admin_url('admin.php')));
        exit;
    }

    public function handle_export()
    {
        if (!current_user_can('obbp_export_csv')) {
            wp_die(esc_html__('Access denied.', 'obydullah-b2b-portal'));
        }
        check_admin_referer(OBBP_NONCE_ADMIN, 'obbp_nonce');
        $entity = sanitize_key(wp_unslash($_POST['entity'] ?? ''));
        $price_list_id = absint(wp_unslash($_POST['price_list_id'] ?? 0));
        if ('price_list' === $entity) {
            $entity = 'price_list_items';
        }
        $headers = [
            'companies' => ['name', 'email', 'phone', 'website', 'tax_id', 'address_line1', 'address_line2', 'city', 'state', 'postcode', 'country', 'status', 'status_note', 'price_list_id', 'currency', 'payment_terms'],
            'catalog' => ['id', 'sku', 'title', 'description', 'unit', 'price', 'currency', 'availability', 'min_qty', 'qty_step', 'lead_time_days', 'tax_rate', 'is_service', 'portal_visible', 'post_status'],
            'price_lists' => ['name', 'slug', 'description', 'currency', 'is_default', 'is_active', 'valid_from', 'valid_to'],
            'rfqs' => ['rfq_number', 'company', 'status', 'subject', 'notes', 'requested_delivery_date', 'item_count', 'submitted_at'],
            'quotes' => ['quote_number', 'company', 'status', 'currency', 'subtotal', 'discount_total', 'tax_total', 'grand_total', 'valid_until'],
            'purchase_orders' => ['po_number', 'company', 'status', 'currency', 'grand_total', 'promised_ship_date'],
            'price_list_items' => ['id', 'price_list_id', 'product_id', 'sku', 'unit_price', 'min_qty', 'qty_step', 'lead_time_days', 'is_active', 'note'],
            'activity' => ['created_at', 'user_login', 'actor_type', 'object_type', 'object_id', 'action', 'severity', 'message'],
        ];
        if (!isset($headers[$entity])) {
            wp_die(esc_html__('Invalid export type.', 'obydullah-b2b-portal'));
        }
        $rows = 'catalog' === $entity ? $this->export_catalog_rows() : OBBP_Repository::export_rows($entity, $price_list_id);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=obbp-' . sanitize_key($entity) . '-' . gmdate('Y-m-d') . '.csv');
        $output = $this->open_output();
        self::write_csv_row($output, $headers[$entity]);
        foreach ($rows as $row) {
            $values = [];
            foreach ($headers[$entity] as $header) {
                $value = $row[$header] ?? '';
                $values[] = self::neutralize_formula($value);
            }
            self::write_csv_row($output, $values);
        }
        $this->close_handle($output);
        OBBP_Repository::log(0, 'export', 0, 'csv_exported', sprintf('Exported %s CSV.', $entity), ['rows' => count($rows)]);
        exit;
    }

    private function export_catalog_rows()
    {
        $rows = [];
        $posts = get_posts(['post_type' => 'obbp_product', 'post_status' => ['publish', 'draft', 'private', 'pending', 'future'], 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC']);
        foreach ($posts as $post) {
            $rows[] = [
                'id' => $post->ID,
                'sku' => (string) get_post_meta($post->ID, '_obbp_sku', true),
                'title' => $post->post_title,
                'description' => $post->post_excerpt,
                'unit' => get_post_meta($post->ID, '_obbp_unit', true) ?: 'pcs',
                'price' => get_post_meta($post->ID, '_obbp_price', true),
                'currency' => get_post_meta($post->ID, '_obbp_currency', true),
                'availability' => get_post_meta($post->ID, '_obbp_availability', true) ?: 'in_stock',
                'min_qty' => get_post_meta($post->ID, '_obbp_min_order_qty', true),
                'qty_step' => get_post_meta($post->ID, '_obbp_qty_step', true),
                'lead_time_days' => get_post_meta($post->ID, '_obbp_lead_time_days', true),
                'tax_rate' => get_post_meta($post->ID, '_obbp_tax_rate', true),
                'is_service' => get_post_meta($post->ID, '_obbp_is_service', true) ? 1 : 0,
                'portal_visible' => get_post_meta($post->ID, '_obbp_portal_visible', true) ? 1 : 0,
                'post_status' => $post->post_status,
            ];
        }
        return $rows;
    }

    private function import_company($row, $apply_status = false, $line = 0)
    {
        global $wpdb;
        $name = sanitize_text_field($row['name'] ?? '');
        $email = sanitize_email($row['email'] ?? '');
        if (!$name || !is_email($email)) {
            return new WP_Error('obbp_row_invalid', __('Company name and valid email are required.', 'obydullah-b2b-portal'));
        }
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE email = %s AND deleted_at IS NULL LIMIT 1', OBBP_Repository::table('companies'), $email), ARRAY_A);
        $settings = get_option(OBBP_OPTION_SETTINGS, []);
        $has_list_column = '' !== (string) ($row['price_list_id'] ?? '');
        $list_id = $has_list_column ? absint($row['price_list_id']) : 0;
        $price_list = $list_id ? OBBP_Repository::row('price_lists', $list_id) : null;
        if ($list_id && (!$price_list || empty($price_list['is_active']))) {
            return new WP_Error('obbp_price_list_invalid', __('Choose an active price list.', 'obydullah-b2b-portal'));
        }
        $currency = strtoupper(sanitize_text_field($row['currency'] ?? ''));
        if (!$currency) {
            $currency = $price_list && !empty($price_list['currency'])
                ? strtoupper($price_list['currency'])
                : strtoupper((string) ($settings['default_currency'] ?? 'BDT'));
        }
        if (!preg_match('/^[A-Z0-9]{3,10}$/', $currency)) {
            return new WP_Error('obbp_row_invalid', __('The company currency is invalid.', 'obydullah-b2b-portal'));
        }
        if ($price_list && !empty($price_list['currency']) && strtoupper($price_list['currency']) !== $currency) {
            return new WP_Error('obbp_currency_mismatch', __('The company currency must match the selected price list currency.', 'obydullah-b2b-portal'));
        }
        $country = strtoupper(sanitize_text_field($row['country'] ?? ($settings['default_country'] ?? 'BD')));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return new WP_Error('obbp_row_invalid', __('The company country code is invalid.', 'obydullah-b2b-portal'));
        }
        $data = [
            'name' => $name,
            'slug' => (sanitize_title($name) ?: sanitize_title($email)) . '-' . substr(md5(strtolower($email)), 0, 6),
            'email' => $email,
            'phone' => sanitize_text_field($row['phone'] ?? ''),
            'website' => esc_url_raw($row['website'] ?? ''),
            'tax_id' => sanitize_text_field($row['tax_id'] ?? ''),
            'address_line1' => sanitize_text_field($row['address_line1'] ?? ''),
            'address_line2' => sanitize_text_field($row['address_line2'] ?? ''),
            'city' => sanitize_text_field($row['city'] ?? ''),
            'state' => sanitize_text_field($row['state'] ?? ''),
            'postcode' => sanitize_text_field($row['postcode'] ?? ''),
            'country' => $country,
            'currency' => $currency,
            'payment_terms' => sanitize_text_field($row['payment_terms'] ?? ''),
        ];
        if ($has_list_column) {
            $data['price_list_id'] = $list_id ?: null;
        }
        if ($existing) {
            $slug_duplicate = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s AND id != %d AND deleted_at IS NULL LIMIT 1', OBBP_Repository::table('companies'), $data['slug'], (int) $existing['id']));
            if ($slug_duplicate) {
                $data['slug'] = $existing['slug'];
            }
            $updated = OBBP_Repository::update('companies', $data, null, ['id' => $existing['id']]);
            if (false === $updated) {
                return new WP_Error('obbp_row_failed', __('Company row could not be updated.', 'obydullah-b2b-portal'));
            }
            $company_id = (int) $existing['id'];
            $current_status = (string) $existing['status'];
        } else {
            $data['status'] = 'pending';
            $id = OBBP_Repository::insert('companies', $data);
            if (!$id) {
                return new WP_Error('obbp_row_failed', __('Company row could not be inserted.', 'obydullah-b2b-portal'));
            }
            $company_id = (int) $id;
            $current_status = 'pending';
            $this->notice($line, __('New companies are created as pending; approve them from the company screen.', 'obydullah-b2b-portal'));
        }
        return $this->apply_company_status($row, $company_id, $current_status, $apply_status, $line);
    }

    private function apply_company_status($row, $company_id, $current_status, $apply_status, $line)
    {
        $requested = sanitize_key($row['status'] ?? '');
        if (!in_array($requested, ['pending', 'approved', 'rejected', 'suspended'], true)) {
            $requested = '';
        }
        if (!$requested) {
            return true;
        }
        if (!$apply_status) {
            /* translators: %s: the company status found in the CSV file. */
            $this->notice($line, sprintf(__('Status "%s" was ignored; tick "Apply company status" to import status changes.', 'obydullah-b2b-portal'), $requested));
            return true;
        }
        if ($requested === $current_status) {
            return true;
        }
        $note = sanitize_textarea_field($row['status_note'] ?? '');
        if (in_array($requested, ['rejected', 'suspended'], true) && '' === trim($note)) {
            $note = __('Applied by CSV import.', 'obydullah-b2b-portal');
        }
        $result = OBBP_Workflow::set_company_status($company_id, $requested, $note);
        if (is_wp_error($result)) {
            /* translators: 1: the company status from the CSV file, 2: why the status change was refused. */
            $this->notice($line, sprintf(__('Status "%1$s" was not applied: %2$s', 'obydullah-b2b-portal'), $requested, $result->get_error_message()));
        }
        return true;
    }

    private function notice($line, $message)
    {
        /* translators: 1: line number in the imported CSV file, 2: the message recorded for that line. */
        $this->notices[] = $line ? sprintf(__('Line %1$d: %2$s', 'obydullah-b2b-portal'), $line, $message) : $message;
    }

    private function import_catalog($row)
    {
        $title = sanitize_text_field($row['title'] ?? '');
        $sku = strtoupper(sanitize_text_field($row['sku'] ?? ''));
        if (!$title || !$sku) {
            return new WP_Error('obbp_row_invalid', __('Catalog title and SKU are required.', 'obydullah-b2b-portal'));
        }
        $existing = get_posts(['post_type' => 'obbp_product', 'post_status' => ['publish', 'draft', 'private', 'pending', 'future'], 'posts_per_page' => 1, 'meta_key' => '_obbp_sku', 'meta_value' => $sku, 'fields' => 'ids']);
        $status = sanitize_key($row['post_status'] ?? 'publish');
        if (!in_array($status, ['publish', 'draft', 'private', 'pending', 'future'], true)) {
            $status = 'publish';
        }
        $post_data = [
            'post_type' => 'obbp_product',
            'post_status' => $status,
            'post_title' => $title,
            'post_excerpt' => sanitize_textarea_field($row['description'] ?? ''),
        ];
        $id = $existing ? wp_update_post(array_merge($post_data, ['ID' => (int) $existing[0]]), true) : wp_insert_post($post_data, true);
        if (is_wp_error($id) || !$id) {
            return new WP_Error('obbp_row_failed', __('Catalog row could not be saved.', 'obydullah-b2b-portal'));
        }
        $currency = strtoupper(sanitize_text_field($row['currency'] ?? ''));
        $currency = $currency && preg_match('/^[A-Z0-9]{3,10}$/', $currency) ? $currency : strtoupper((string) OBBP_Catalog::default_currency());
        $availability = sanitize_key($row['availability'] ?? 'in_stock');
        $min_qty = '' === (string) ($row['min_qty'] ?? '') ? 1 : $row['min_qty'];
        $qty_step = '' === (string) ($row['qty_step'] ?? '') ? 1 : $row['qty_step'];
        $raw_price = '' === (string) ($row['price'] ?? '') ? 0 : $row['price'];
        $raw_tax = '' === (string) ($row['tax_rate'] ?? '') ? 0 : $row['tax_rate'];
        if (!is_numeric($raw_price) || !is_finite((float) $raw_price) || (float) $raw_price < 0 || !is_numeric($min_qty) || !is_finite((float) $min_qty) || (float) $min_qty <= 0 || !is_numeric($qty_step) || !is_finite((float) $qty_step) || (float) $qty_step <= 0 || !is_numeric($raw_tax) || !is_finite((float) $raw_tax) || (float) $raw_tax < 0 || (float) $raw_tax > 100) {
            return new WP_Error('obbp_row_invalid', __('Catalog numeric values are invalid.', 'obydullah-b2b-portal'));
        }
        $is_service = filter_var($row['is_service'] ?? 0, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $portal_visible = filter_var($row['portal_visible'] ?? 1, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $meta = [
            '_obbp_sku' => $sku,
            '_obbp_unit' => sanitize_text_field($row['unit'] ?? 'pcs') ?: 'pcs',
            '_obbp_price' => max(0, OBBP_Repository::money($raw_price)),
            '_obbp_currency' => $currency,
            '_obbp_availability' => array_key_exists($availability, OBBP_Catalog::availability_options()) ? $availability : 'in_stock',
            '_obbp_min_order_qty' => self::positive_quantity($min_qty),
            '_obbp_qty_step' => self::positive_quantity($qty_step),
            '_obbp_lead_time_days' => absint($row['lead_time_days'] ?? 0),
            '_obbp_tax_rate' => min(100, max(0, OBBP_Repository::quantity($raw_tax))),
            '_obbp_is_service' => $is_service,
            '_obbp_portal_visible' => $portal_visible,
        ];
        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        return true;
    }

    private function import_price_list($row)
    {
        global $wpdb;
        $name = sanitize_text_field($row['name'] ?? '');
        $currency = strtoupper(sanitize_text_field($row['currency'] ?? ''));
        if (!$currency) {
            $currency = strtoupper((string) OBBP_Catalog::default_currency());
        }
        if (!$name || !preg_match('/^[A-Z0-9]{3,10}$/', $currency)) {
            return new WP_Error('obbp_row_invalid', __('A valid price list name and currency are required.', 'obydullah-b2b-portal'));
        }
        $slug = sanitize_title(trim((string) ($row['slug'] ?? '')));
        if (!$slug) {
            $slug = sanitize_title($name);
        }
        if (!$slug) {
            return new WP_Error('obbp_row_invalid', __('The price list slug is invalid.', 'obydullah-b2b-portal'));
        }
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE slug = %s AND deleted_at IS NULL LIMIT 1', OBBP_Repository::table('price_lists'), $slug), ARRAY_A);
        if (!$existing) {
            $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE name = %s AND deleted_at IS NULL LIMIT 1', OBBP_Repository::table('price_lists'), $name), ARRAY_A);
        }
        if ($existing) {
            $slug = sanitize_title($existing['slug'] ?: $slug);
        }
        $valid_from = sanitize_text_field($row['valid_from'] ?? '');
        $valid_to = sanitize_text_field($row['valid_to'] ?? '');
        foreach (['valid_from' => $valid_from, 'valid_to' => $valid_to] as $field => $value) {
            if ($value) {
                $date = DateTime::createFromFormat('Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    /* translators: %s: name of the date field, such as valid_from. */
                    return new WP_Error('obbp_row_invalid', sprintf(__('The %s date is invalid.', 'obydullah-b2b-portal'), $field));
                }
            }
        }
        if ($valid_from && $valid_to && $valid_from > $valid_to) {
            return new WP_Error('obbp_row_invalid', __('The price list end date must not precede its start date.', 'obydullah-b2b-portal'));
        }
        $is_default = filter_var($row['is_default'] ?? 0, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $is_active = '' === (string) ($row['is_active'] ?? '') ? 1 : (filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        if ($is_default && !$is_active) {
            return new WP_Error('obbp_row_invalid', __('A default price list must be active.', 'obydullah-b2b-portal'));
        }
        $data = [
            'name' => $name,
            'slug' => $slug,
            'description' => sanitize_textarea_field($row['description'] ?? ''),
            'currency' => $currency,
            'is_default' => $is_default,
            'is_active' => $is_active,
            'valid_from' => $valid_from ?: null,
            'valid_to' => $valid_to ?: null,
        ];
        $wpdb->query('START TRANSACTION');
        if ($is_default) {
            $other_default_ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE id != %d AND is_default = 1 AND deleted_at IS NULL', OBBP_Repository::table('price_lists'), absint($existing['id'] ?? 0)));
            $cleared = OBBP_Repository::update_ids('price_lists', ['is_default' => 0], ['%d'], $other_default_ids);
            if (false === $cleared) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_row_failed', __('The default price list could not be updated.', 'obydullah-b2b-portal'));
            }
        }
        if ($existing) {
            $updated = OBBP_Repository::update('price_lists', $data, null, ['id' => $existing['id']]);
            if (false === $updated) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_row_failed', __('Price list row could not be updated.', 'obydullah-b2b-portal'));
            }
        } else {
            $id = OBBP_Repository::insert('price_lists', $data);
            if (!$id) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_row_failed', __('Price list row could not be inserted.', 'obydullah-b2b-portal'));
            }
        }
        $wpdb->query('COMMIT');
        return true;
    }

    private function import_price($row, $fallback_list_id = 0)
    {
        global $wpdb;
        $raw_list = $row['price_list_id'] ?? '';
        $list_id = '' !== (string) $raw_list ? absint($raw_list) : absint($fallback_list_id);
        $sku = strtoupper(sanitize_text_field($row['sku'] ?? ''));
        $price_list = OBBP_Repository::row('price_lists', $list_id);
        if (!$list_id || !$price_list || !$sku) {
            return new WP_Error('obbp_row_invalid', __('A valid price list ID and SKU are required.', 'obydullah-b2b-portal'));
        }
        $product_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM %i WHERE meta_key = '_obbp_sku' AND meta_value = %s AND post_id IN (SELECT ID FROM %i WHERE post_type = 'obbp_product' AND post_status != 'trash') LIMIT 1", $wpdb->postmeta, $sku, $wpdb->posts));
        if (!$product_id) {
            /* translators: %s: the product SKU that could not be matched. */
            return new WP_Error('obbp_sku_missing', sprintf(__('No catalog product uses SKU %s.', 'obydullah-b2b-portal'), $sku));
        }
        $raw_price = $row['unit_price'] ?? 0;
        $raw_min = '' === (string) ($row['min_qty'] ?? '') ? 1 : $row['min_qty'];
        $raw_step = '' === (string) ($row['qty_step'] ?? '') ? 1 : $row['qty_step'];
        if (!is_numeric($raw_price) || !is_finite((float) $raw_price) || (float) $raw_price < 0 || !is_numeric($raw_min) || !is_finite((float) $raw_min) || (float) $raw_min <= 0 || !is_numeric($raw_step) || !is_finite((float) $raw_step) || (float) $raw_step <= 0) {
            return new WP_Error('obbp_row_invalid', __('Price list numeric values are invalid.', 'obydullah-b2b-portal'));
        }
        $is_active = '' === (string) ($row['is_active'] ?? '')
            ? 1
            : (filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        $data = [
            'price_list_id' => $list_id,
            'product_id' => (int) $product_id,
            'unit_price' => OBBP_Repository::money($raw_price),
            'min_qty' => self::positive_quantity($raw_min),
            'qty_step' => self::positive_quantity($raw_step),
            'lead_time_days' => absint($row['lead_time_days'] ?? 0),
            'is_active' => $is_active,
            'note' => substr(sanitize_text_field($row['note'] ?? ''), 0, 255),
            'updated_at' => OBBP_Repository::now(),
        ];
        $existing = $wpdb->get_row($wpdb->prepare('SELECT id, created_at FROM %i WHERE price_list_id = %d AND product_id = %d LIMIT 1', OBBP_Repository::table('price_list_items'), $list_id, (int) $product_id), ARRAY_A);
        if ($existing) {
            $updated = OBBP_Repository::update('price_list_items', $data, ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s'], ['id' => $existing['id']]);
            if (false === $updated) {
                return new WP_Error('obbp_row_failed', __('Price list row could not be saved.', 'obydullah-b2b-portal'));
            }
        } else {
            $data['created_at'] = OBBP_Repository::now();
            $id = OBBP_Repository::insert('price_list_items', $data, ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);
            if (!$id) {
                return new WP_Error('obbp_row_failed', __('Price list row could not be saved.', 'obydullah-b2b-portal'));
            }
        }
        return true;
    }

    private static function positive_quantity($value)
    {
        $quantity = OBBP_Repository::quantity($value);
        return $quantity > 0 ? $quantity : 1;
    }

    private function import_redirect($error)
    {
        wp_safe_redirect(add_query_arg(['page' => 'obbp-transfer', 'obbp_error' => rawurlencode($error->get_error_message())], admin_url('admin.php')));
        exit;
    }

    private static function read_csv_row($handle)
    {
        return fgetcsv($handle, null, ',', '"', '');
    }

    private static function row_has_content($values)
    {
        foreach ($values as $value) {
            if ('' !== trim((string) $value)) {
                return true;
            }
        }
        return false;
    }

    private static function fit_csv_row($values, $expected)
    {
        $values = array_values((array) $values);
        while (count($values) > $expected && '' === trim((string) end($values))) {
            array_pop($values);
        }
        if (count($values) > $expected) {
            return false;
        }
        while (count($values) < $expected) {
            $values[] = '';
        }
        return $values;
    }

    private static function neutralize_formula($value)
    {
        if (!is_string($value) || '' === $value || is_numeric($value)) {
            return $value;
        }
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    private static function write_csv_row($handle, $values)
    {
        return fputcsv($handle, $values, ',', '"', '');
    }
}
