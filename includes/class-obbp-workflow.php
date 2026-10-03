<?php
if (!defined('ABSPATH')) {
    exit;
}

final class OBBP_Workflow
{
    public static function set_company_status($company_id, $status, $note = '')
    {
        global $wpdb;
        $company = OBBP_Repository::row('companies', $company_id);
        if (!$company) {
            return new WP_Error('obbp_company_missing', __('Company not found.', 'obydullah-b2b-portal'));
        }
        $allowed = [
            'pending' => ['approved', 'rejected'],
            'approved' => ['suspended'],
            'rejected' => ['approved'],
            'suspended' => ['approved', 'rejected'],
        ];
        if (!isset($allowed[$company['status']]) || !in_array($status, $allowed[$company['status']], true)) {
            return new WP_Error('obbp_invalid_status', __('That company status transition is not allowed.', 'obydullah-b2b-portal'));
        }
        if (in_array($status, ['rejected', 'suspended'], true) && '' === trim($note)) {
            return new WP_Error('obbp_note_required', __('Please provide a reason.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('START TRANSACTION');
        $locked = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('companies'), $company_id), ARRAY_A);
        if (!$locked || !isset($allowed[$locked['status']]) || !in_array($status, $allowed[$locked['status']], true)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_invalid_status', __('That company status transition is no longer allowed.', 'obydullah-b2b-portal'));
        }
        $company = $locked;
        $created_user_id = 0;
        if ('approved' === $status) {
            $primary = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE company_id = %d AND is_primary = 1 AND status = \'active\' AND deleted_at IS NULL LIMIT 1 FOR UPDATE', OBBP_Repository::table('company_contacts'), $company_id), ARRAY_A);
            if (!$primary) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_primary_contact_required', __('An active primary contact is required before approval.', 'obydullah-b2b-portal'));
            }
            $provisioned = self::provision_portal_user($company, $primary);
            if (is_wp_error($provisioned)) {
                $wpdb->query('ROLLBACK');
                $error_data = $provisioned->get_error_data();
                if (is_array($error_data) && !empty($error_data['created_user_id'])) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                    wp_delete_user(absint($error_data['created_user_id']));
                }
                return $provisioned;
            }
            $user_id = absint($provisioned['user_id']);
            $created_user_id = absint($provisioned['created_user_id']);
            $data = [
                'status' => 'approved',
                'status_note' => '',
                'user_id' => $user_id,
                'approved_at' => OBBP_Repository::now(),
                'reviewed_at' => OBBP_Repository::now(),
                'reviewed_by' => get_current_user_id(),
            ];
        } else {
            $data = [
                'status' => $status,
                'status_note' => sanitize_textarea_field($note),
                'reviewed_at' => OBBP_Repository::now(),
                'reviewed_by' => get_current_user_id(),
            ];
        }
        $updated = OBBP_Repository::update('companies', $data, null, ['id' => $company_id, 'status' => $company['status']]);
        if (false === $updated || 0 === $updated) {
            $wpdb->query('ROLLBACK');
            if ($created_user_id) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user($created_user_id);
            }
            return new WP_Error('obbp_update_failed', __('The company could not be updated.', 'obydullah-b2b-portal'));
        }
        if (false === $wpdb->query('COMMIT')) {
            if ($created_user_id) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user($created_user_id);
            }
            return new WP_Error('obbp_update_failed', __('The company status could not be committed.', 'obydullah-b2b-portal'));
        }
        OBBP_Repository::log($company_id, 'company', $company_id, 'status_' . $status, sprintf('Company status changed to %s.', $status), ['from' => $company['status'], 'to' => $status, 'note' => sanitize_textarea_field($note)]);
        OBBP_Mailer::company_status($company_id, $status);
        return true;
    }

    public static function create_rfq($company_id, $user_id, $source = 'portal', $parent_rfq_id = 0)
    {
        $company = OBBP_Repository::row('companies', $company_id);
        if (!$company || 'approved' !== $company['status']) {
            return new WP_Error('obbp_company_unavailable', __('Your company is not approved for portal access.', 'obydullah-b2b-portal'));
        }
        if (!OBBP_Repository::company_owns_user($company_id, $user_id)) {
            return new WP_Error('obbp_company_forbidden', __('You are not linked to this company.', 'obydullah-b2b-portal'));
        }
        $contact_id = self::contact_id($company_id, $user_id);
        $currency = self::company_currency($company);
        $id = OBBP_Repository::insert('rfqs', [
            'rfq_number' => 'TMP-' . wp_generate_uuid4(),
            'company_id' => $company_id,
            'created_by_user_id' => $user_id,
            'contact_id' => $contact_id,
            'parent_rfq_id' => $parent_rfq_id ? absint($parent_rfq_id) : null,
            'source' => sanitize_key($source),
            'status' => 'draft',
            'currency' => $currency,
            'item_count' => 0,
        ]);
        if (!$id) {
            return new WP_Error('obbp_insert_failed', __('The RFQ could not be created.', 'obydullah-b2b-portal'));
        }
        $reference_updated = OBBP_Repository::update('rfqs', ['rfq_number' => OBBP_Repository::reference('RFQ', $id)], ['%s'], ['id' => $id]);
        if (false === $reference_updated) {
            return new WP_Error('obbp_update_failed', __('The RFQ reference could not be created.', 'obydullah-b2b-portal'));
        }
        OBBP_Repository::log($company_id, 'rfq', $id, 'created', 'Draft RFQ created.');
        return $id;
    }

    public static function add_rfq_item($product_id, $quantity, $note, $user_id)
    {
        global $wpdb;
        $company = OBBP_Repository::company_for_user($user_id);
        if (!$company) {
            return new WP_Error('obbp_company_missing', __('Company access is required.', 'obydullah-b2b-portal'));
        }
        $product = get_post($product_id);
        if (!$product || 'obbp_product' !== $product->post_type || 'publish' !== $product->post_status || !get_post_meta($product_id, '_obbp_portal_visible', true) || 'discontinued' === get_post_meta($product_id, '_obbp_availability', true)) {
            return new WP_Error('obbp_product_unavailable', __('This product is not available.', 'obydullah-b2b-portal'));
        }
        $pricing = OBBP_Repository::price_for_product($product_id, $company);
        if (empty($pricing['currency_ok'])) {
            return new WP_Error('obbp_currency_mismatch', __('This product is not priced in your company currency.', 'obydullah-b2b-portal'));
        }
        $valid = self::validate_quantity($quantity, $pricing['min_qty'], $pricing['step']);
        if (is_wp_error($valid)) {
            return $valid;
        }
        $wpdb->query('START TRANSACTION');
        $company_locked = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('companies'), $company['id']));
        if (!$company_locked) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_company_missing', __('Company not found.', 'obydullah-b2b-portal'));
        }
        $rfq = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE company_id = %d AND created_by_user_id = %d AND status = 'draft' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE", OBBP_Repository::table('rfqs'), $company['id'], $user_id), ARRAY_A);
        $rfq_id = $rfq ? (int) $rfq['id'] : self::create_rfq($company['id'], $user_id);
        if (is_wp_error($rfq_id)) {
            $wpdb->query('ROLLBACK');
            return $rfq_id;
        }
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE rfq_id = %d AND product_id = %d AND deleted_at IS NULL LIMIT 1 FOR UPDATE", OBBP_Repository::table('rfq_items'), $rfq_id, $product_id), ARRAY_A);
        if ($existing) {
            $new_quantity = OBBP_Repository::quantity((float) $existing['quantity'] + (float) $quantity);
            $valid = self::validate_quantity($new_quantity, $pricing['min_qty'], $pricing['step']);
            if (is_wp_error($valid)) {
                $wpdb->query('ROLLBACK');
                return $valid;
            }
            $updated = OBBP_Repository::update('rfq_items', ['quantity' => $new_quantity, 'note' => sanitize_textarea_field($note)], null, ['id' => $existing['id']]);
            if (false === $updated) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_update_failed', __('The RFQ item could not be updated.', 'obydullah-b2b-portal'));
            }
            $item_id = (int) $existing['id'];
        } else {
            $item_id = OBBP_Repository::insert('rfq_items', [
                'rfq_id' => $rfq_id,
                'product_id' => $product_id,
                'sku' => sanitize_text_field(get_post_meta($product_id, '_obbp_sku', true)),
                'name' => $product->post_title,
                'description' => $product->post_excerpt,
                'unit' => sanitize_text_field(get_post_meta($product_id, '_obbp_unit', true)) ?: 'pcs',
                'quantity' => OBBP_Repository::quantity($quantity),
                'note' => sanitize_textarea_field($note),
            ]);
            if (!$item_id) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_insert_failed', __('The RFQ item could not be added.', 'obydullah-b2b-portal'));
            }
        }
        self::refresh_rfq_item_count($rfq_id);
        $wpdb->query('COMMIT');
        OBBP_Repository::log($company['id'], 'rfq', $rfq_id, 'item_added', 'Catalog item added to RFQ.', ['product_id' => $product_id, 'quantity' => OBBP_Repository::quantity($quantity)]);
        return ['rfq_id' => $rfq_id, 'item_id' => $item_id];
    }

    public static function save_rfq($rfq_id, $user_id, $data)
    {
        global $wpdb;
        $rfq = self::company_rfq($rfq_id, $user_id);
        if (!$rfq || 'draft' !== $rfq['status']) {
            return new WP_Error('obbp_rfq_uneditable', __('This RFQ can no longer be edited.', 'obydullah-b2b-portal'));
        }
        $date = sanitize_text_field($data['requested_delivery_date'] ?? '');
        if ($date && !self::valid_date($date)) {
            return new WP_Error('obbp_invalid_date', __('Enter a valid delivery date.', 'obydullah-b2b-portal'));
        }
        $update = [
            'subject' => sanitize_text_field($data['subject'] ?? ''),
            'notes' => sanitize_textarea_field($data['notes'] ?? ''),
            'requested_delivery_date' => $date ? $date : null,
            'updated_at' => OBBP_Repository::now(),
        ];
        $wpdb->query('START TRANSACTION');
        $locked = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND company_id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('rfqs'), $rfq_id, $rfq['company_id']), ARRAY_A);
        if (!$locked || 'draft' !== $locked['status'] || !OBBP_Repository::company_owns_user($locked['company_id'], $user_id)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_rfq_uneditable', __('This RFQ can no longer be edited.', 'obydullah-b2b-portal'));
        }
        $updated = $wpdb->update(OBBP_Repository::table('rfqs'), $update, ['id' => $rfq_id], ['%s', '%s', '%s', '%s']);
        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_update_failed', __('The RFQ could not be updated.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('COMMIT');
        OBBP_Repository::log($rfq['company_id'], 'rfq', $rfq_id, 'updated', 'RFQ details updated.');
        return true;
    }

    public static function submit_rfq($rfq_id, $user_id)
    {
        global $wpdb;
        $rfq = self::company_rfq($rfq_id, $user_id);
        if (!$rfq || 'draft' !== $rfq['status']) {
            return new WP_Error('obbp_invalid_rfq', __('Only a draft RFQ can be submitted.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('START TRANSACTION');
        $locked = $wpdb->get_row($wpdb->prepare('SELECT status FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('rfqs'), $rfq_id), ARRAY_A);
        if (!$locked || 'draft' !== $locked['status']) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_invalid_rfq', __('This RFQ has already been submitted.', 'obydullah-b2b-portal'));
        }
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE rfq_id = %d AND deleted_at IS NULL ORDER BY id ASC', OBBP_Repository::table('rfq_items'), $rfq_id), ARRAY_A);
        if (!$items) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_empty_rfq', __('Add at least one item before submitting.', 'obydullah-b2b-portal'));
        }
        $company = OBBP_Repository::row('companies', $rfq['company_id']);
        foreach ($items as $item) {
            $pricing = OBBP_Repository::price_for_product($item['product_id'], $company);
            if (is_wp_error(self::validate_quantity($item['quantity'], $pricing['min_qty'], $pricing['step']))) {
                $wpdb->query('ROLLBACK');
                /* translators: %s: product name from the RFQ line. */
                return new WP_Error('obbp_invalid_quantity', sprintf(__('%s no longer meets the current order quantity rules. Please review the quantity and try again.', 'obydullah-b2b-portal'), $item['name']));
            }
        }
        $updated = $wpdb->update(OBBP_Repository::table('rfqs'), ['status' => 'submitted', 'submitted_at' => OBBP_Repository::now(), 'updated_at' => OBBP_Repository::now()], ['id' => $rfq_id], ['%s', '%s', '%s']);
        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_update_failed', __('The RFQ could not be submitted.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('COMMIT');
        OBBP_Repository::log($rfq['company_id'], 'rfq', $rfq_id, 'submitted', 'RFQ submitted by customer.');
        OBBP_Mailer::rfq_submitted($rfq_id);
        return true;
    }

    public static function update_rfq_item($item_id, $user_id, $quantity, $note = '')
    {
        global $wpdb;
        $item = $wpdb->get_row($wpdb->prepare('SELECT i.*, r.company_id, r.status FROM %i i INNER JOIN %i r ON r.id = i.rfq_id WHERE i.id = %d AND i.deleted_at IS NULL AND r.deleted_at IS NULL LIMIT 1', OBBP_Repository::table('rfq_items'), OBBP_Repository::table('rfqs'), $item_id), ARRAY_A);
        if (!$item || !OBBP_Repository::company_owns_user($item['company_id'], $user_id) || 'draft' !== $item['status']) {
            return new WP_Error('obbp_item_unavailable', __('The RFQ item cannot be edited.', 'obydullah-b2b-portal'));
        }
        $pricing = OBBP_Repository::price_for_product($item['product_id'], OBBP_Repository::row('companies', $item['company_id']));
        if (empty($pricing['currency_ok'])) {
            return new WP_Error('obbp_currency_mismatch', __('This product is not priced in your company currency.', 'obydullah-b2b-portal'));
        }
        $valid = self::validate_quantity($quantity, $pricing['min_qty'], $pricing['step']);
        if (is_wp_error($valid)) {
            return $valid;
        }
        $wpdb->query('START TRANSACTION');
        $locked_rfq = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND company_id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('rfqs'), $item['rfq_id'], $item['company_id']), ARRAY_A);
        $locked_item = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND rfq_id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('rfq_items'), $item_id, $item['rfq_id']), ARRAY_A);
        if (!$locked_rfq || !$locked_item || 'draft' !== $locked_rfq['status'] || !OBBP_Repository::company_owns_user($locked_rfq['company_id'], $user_id)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_item_unavailable', __('The RFQ item cannot be edited.', 'obydullah-b2b-portal'));
        }
        $updated = $wpdb->update(OBBP_Repository::table('rfq_items'), ['quantity' => OBBP_Repository::quantity($quantity), 'note' => sanitize_textarea_field($note), 'updated_at' => OBBP_Repository::now()], ['id' => $item_id], ['%f', '%s', '%s']);
        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_update_failed', __('The RFQ item could not be updated.', 'obydullah-b2b-portal'));
        }
        self::refresh_rfq_item_count($item['rfq_id']);
        $wpdb->query('COMMIT');
        return true;
    }

    public static function remove_rfq_item($item_id, $user_id)
    {
        global $wpdb;
        $item = $wpdb->get_row($wpdb->prepare('SELECT i.rfq_id, i.product_id, r.company_id, r.status FROM %i i INNER JOIN %i r ON r.id = i.rfq_id WHERE i.id = %d AND i.deleted_at IS NULL AND r.deleted_at IS NULL LIMIT 1', OBBP_Repository::table('rfq_items'), OBBP_Repository::table('rfqs'), $item_id), ARRAY_A);
        if (!$item || !OBBP_Repository::company_owns_user($item['company_id'], $user_id) || 'draft' !== $item['status']) {
            return new WP_Error('obbp_item_unavailable', __('The RFQ item cannot be removed.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('START TRANSACTION');
        $locked_rfq = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND company_id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('rfqs'), $item['rfq_id'], $item['company_id']), ARRAY_A);
        $locked_item = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND rfq_id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('rfq_items'), $item_id, $item['rfq_id']), ARRAY_A);
        if (!$locked_rfq || !$locked_item || 'draft' !== $locked_rfq['status'] || !OBBP_Repository::company_owns_user($locked_rfq['company_id'], $user_id)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_item_unavailable', __('The RFQ item cannot be removed.', 'obydullah-b2b-portal'));
        }
        $deleted = $wpdb->update(OBBP_Repository::table('rfq_items'), ['deleted_at' => OBBP_Repository::now(), 'updated_at' => OBBP_Repository::now()], ['id' => $item_id, 'rfq_id' => $item['rfq_id'], 'deleted_at' => null], ['%s', '%s']);
        if (false === $deleted) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_delete_failed', __('The RFQ item could not be removed.', 'obydullah-b2b-portal'));
        }
        self::refresh_rfq_item_count($item['rfq_id']);
        $wpdb->query('COMMIT');
        return true;
    }

    public static function create_quote($rfq_id)
    {
        global $wpdb;
        $rfq = OBBP_Repository::row('rfqs', $rfq_id);
        if (!$rfq) {
            return new WP_Error('obbp_rfq_missing', __('RFQ not found.', 'obydullah-b2b-portal'));
        }
        if (!in_array($rfq['status'], ['submitted', 'under_review', 'quoted'], true)) {
            return new WP_Error('obbp_rfq_not_quotable', __('This RFQ cannot be quoted in its current state.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('START TRANSACTION');
        $locked_rfq = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('rfqs'), $rfq_id), ARRAY_A);
        if (!$locked_rfq || !in_array($locked_rfq['status'], ['submitted', 'under_review', 'quoted'], true)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_rfq_not_quotable', __('This RFQ cannot be quoted in its current state.', 'obydullah-b2b-portal'));
        }
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM %i WHERE rfq_id = %d AND status IN ('draft', 'sent', 'viewed', 'accepted', 'partially_accepted') AND deleted_at IS NULL ORDER BY id DESC LIMIT 1", OBBP_Repository::table('quotes'), $rfq_id), ARRAY_A);
        if ($existing) {
            $wpdb->query('ROLLBACK');
            if ('draft' === $existing['status']) {
                return absint($existing['id']);
            }
            return new WP_Error('obbp_quote_exists', __('This RFQ already has an active quote.', 'obydullah-b2b-portal'));
        }
        $company = OBBP_Repository::row('companies', $locked_rfq['company_id']);
        if (!$company) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_company_missing', __('Company not found.', 'obydullah-b2b-portal'));
        }
        $revision = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(revision), 0) + 1 FROM %i WHERE rfq_id = %d', OBBP_Repository::table('quotes'), $rfq_id));
        $quote_id = OBBP_Repository::insert('quotes', [
            'quote_number' => 'TMP-' . wp_generate_uuid4(),
            'company_id' => $locked_rfq['company_id'],
            'rfq_id' => $rfq_id,
            'revision' => max(1, $revision),
            'status' => 'draft',
            'issue_date' => current_time('Y-m-d'),
            'valid_until' => self::site_date_offset(30),
            'currency' => self::company_currency($company),
            'payment_terms' => sanitize_text_field($company['payment_terms'] ?? ''),
        ]);
        if (!$quote_id) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_insert_failed', __('The quote could not be created.', 'obydullah-b2b-portal'));
        }
        if (false === OBBP_Repository::update('quotes', ['quote_number' => OBBP_Repository::reference('QT', $quote_id)], ['%s'], ['id' => $quote_id])) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_update_failed', __('The quote reference could not be created.', 'obydullah-b2b-portal'));
        }
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE rfq_id = %d AND deleted_at IS NULL ORDER BY line_sort ASC, id ASC', OBBP_Repository::table('rfq_items'), $rfq_id), ARRAY_A);
        $inserted = 0;
        foreach ($items as $index => $item) {
            $product = get_post($item['product_id']);
            if (!$product || 'obbp_product' !== $product->post_type) {
                continue;
            }
            $pricing = OBBP_Repository::price_for_product($item['product_id'], $company);
            if (empty($pricing['currency_ok'])) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_currency_mismatch', __('A product is not priced in the company currency.', 'obydullah-b2b-portal'));
            }
            $line_price = (float) $item['target_price'] > 0 ? (float) $item['target_price'] : (float) $pricing['price'];
            $inserted_item = OBBP_Repository::insert('quote_items', [
                'quote_id' => $quote_id,
                'rfq_item_id' => $item['id'],
                'product_id' => $item['product_id'],
                'sku' => sanitize_text_field($item['sku']),
                'name' => sanitize_text_field($item['name']),
                'description' => sanitize_textarea_field($item['description']),
                'unit' => sanitize_text_field($item['unit']) ?: 'pcs',
                'quantity' => max(0, OBBP_Repository::quantity($item['quantity'])),
                'list_price' => max(0, OBBP_Repository::money($pricing['price'])),
                'unit_price' => max(0, OBBP_Repository::money($line_price)),
                'tax_rate' => min(100, max(0, OBBP_Repository::quantity(get_post_meta($item['product_id'], '_obbp_tax_rate', true)))),
                'lead_time_days' => absint($pricing['lead_time_days']),
                'note' => sanitize_textarea_field($item['note']),
                'line_sort' => $index,
            ]);
            if (!$inserted_item) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_quote_item_failed', __('A quote item could not be created.', 'obydullah-b2b-portal'));
            }
            $inserted++;
        }
        if (!$inserted || !self::recalculate_quote($quote_id)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_empty', __('The RFQ has no valid quote items.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('COMMIT');
        OBBP_Repository::log($locked_rfq['company_id'], 'quote', $quote_id, 'created', 'Draft quote created from RFQ.', ['rfq_id' => $rfq_id]);
        return $quote_id;
    }

    public static function save_quote($quote_id, $data)
    {
        global $wpdb;
        $quote = OBBP_Repository::row('quotes', $quote_id);
        if (!$quote || 'draft' !== $quote['status']) {
            return new WP_Error('obbp_quote_immutable', __('Only draft quotes can be changed.', 'obydullah-b2b-portal'));
        }
        $discount_type = sanitize_key($data['discount_type'] ?? '');
        if (!in_array($discount_type, ['', 'fixed', 'percent'], true)) {
            $discount_type = '';
        }
        $valid_until = sanitize_text_field($data['valid_until'] ?? '');
        if (!self::valid_date($valid_until)) {
            return new WP_Error('obbp_invalid_date', __('Enter a valid quote expiry date.', 'obydullah-b2b-portal'));
        }
        $discount_value = max(0, OBBP_Repository::quantity($data['discount_value'] ?? 0));
        if ('percent' === $discount_type) {
            $discount_value = min(100, $discount_value);
        }
        $prepared = [];
        $lines = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
        foreach (array_values($lines) as $index => $line) {
            $product_id = absint($line['product_id'] ?? 0);
            $product = get_post($product_id);
            if (!$product || 'obbp_product' !== $product->post_type) {
                continue;
            }
            $quantity = OBBP_Repository::quantity($line['quantity'] ?? 0);
            $unit_price = max(0, OBBP_Repository::money($line['unit_price'] ?? 0));
            $gross = OBBP_Repository::money($quantity * $unit_price);
            if ($quantity <= 0 || $gross <= 0) {
                continue;
            }
            $line_discount = min($gross, max(0, OBBP_Repository::money($line['line_discount'] ?? 0)));
            $prepared[] = [
                'quote_id' => $quote_id,
                'rfq_item_id' => absint($line['rfq_item_id'] ?? 0) ?: null,
                'product_id' => $product_id,
                'sku' => sanitize_text_field(get_post_meta($product_id, '_obbp_sku', true)),
                'name' => sanitize_text_field($product->post_title),
                'description' => sanitize_textarea_field($product->post_excerpt),
                'unit' => sanitize_text_field(get_post_meta($product_id, '_obbp_unit', true)) ?: 'pcs',
                'quantity' => $quantity,
                'list_price' => max(0, OBBP_Repository::money($line['list_price'] ?? 0)),
                'unit_price' => $unit_price,
                'line_discount' => $line_discount,
                'tax_rate' => min(100, max(0, OBBP_Repository::quantity($line['tax_rate'] ?? 0))),
                'lead_time_days' => absint($line['lead_time_days'] ?? 0),
                'note' => sanitize_textarea_field($line['note'] ?? ''),
                'line_sort' => $index,
            ];
        }
        if (!$prepared) {
            return new WP_Error('obbp_quote_empty', __('Add at least one valid quote item.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('START TRANSACTION');
        $locked_quote = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('quotes'), $quote_id), ARRAY_A);
        if (!$locked_quote || 'draft' !== $locked_quote['status']) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_immutable', __('Only draft quotes can be changed.', 'obydullah-b2b-portal'));
        }
        $quote = $locked_quote;
        $updated = $wpdb->update(OBBP_Repository::table('quotes'), [
            'issue_date' => self::valid_date($data['issue_date'] ?? '') ? sanitize_text_field($data['issue_date']) : current_time('Y-m-d'),
            'valid_until' => $valid_until,
            'discount_type' => $discount_type,
            'discount_value' => $discount_value,
            'shipping_total' => max(0, OBBP_Repository::money($data['shipping_total'] ?? 0)),
            'payment_terms' => sanitize_text_field($data['payment_terms'] ?? ''),
            'delivery_terms' => sanitize_text_field($data['delivery_terms'] ?? ''),
            'notes' => sanitize_textarea_field($data['notes'] ?? ''),
            'internal_notes' => sanitize_textarea_field($data['internal_notes'] ?? ''),
            'updated_at' => OBBP_Repository::now(),
        ], ['id' => $quote_id], ['%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s']);
        if (false === $updated || false === $wpdb->delete(OBBP_Repository::table('quote_items'), ['quote_id' => $quote_id], ['%d'])) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_update_failed', __('The quote could not be updated.', 'obydullah-b2b-portal'));
        }
        foreach ($prepared as $line) {
            if (!OBBP_Repository::insert('quote_items', $line)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_quote_item_failed', __('A quote item could not be saved.', 'obydullah-b2b-portal'));
            }
        }
        if (!self::recalculate_quote($quote_id)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_recalculate_failed', __('The quote totals could not be calculated.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('COMMIT');
        OBBP_Repository::log($quote['company_id'], 'quote', $quote_id, 'updated', 'Draft quote updated.');
        return true;
    }

    public static function recalculate_quote($quote_id)
    {
        global $wpdb;
        $quote = OBBP_Repository::row('quotes', $quote_id);
        if (!$quote) {
            return false;
        }
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE quote_id = %d AND deleted_at IS NULL ORDER BY line_sort ASC, id ASC', OBBP_Repository::table('quote_items'), $quote_id), ARRAY_A);
        $subtotal = 0.0;
        $tax_total = 0.0;
        foreach ($items as $item) {
            $quantity = max(0, (float) $item['quantity']);
            $gross = max(0, OBBP_Repository::money($quantity * (float) $item['unit_price']));
            $line_discount = min($gross, max(0, OBBP_Repository::money((float) $item['line_discount'])));
            $line_total = max(0, OBBP_Repository::money($gross - $line_discount));
            $line_tax = max(0, OBBP_Repository::money($line_total * (min(100, max(0, (float) $item['tax_rate'])) / 100)));
            if (false === $wpdb->update(OBBP_Repository::table('quote_items'), ['tax_amount' => $line_tax, 'line_total' => $line_total, 'line_tax_total' => $line_tax], ['id' => $item['id']], ['%f', '%f', '%f'])) {
                return false;
            }
            $subtotal += $line_total;
            $tax_total += $line_tax;
        }
        $discount_total = match ($quote['discount_type']) {
            'percent' => OBBP_Repository::money($subtotal * (min(100, max(0, (float) $quote['discount_value'])) / 100)),
            'fixed' => max(0, OBBP_Repository::money($quote['discount_value'])),
            default => 0.0,
        };
        $discount_total = min($subtotal, $discount_total);
        $grand_total = max(0, OBBP_Repository::money($subtotal - $discount_total + $tax_total + max(0, (float) $quote['shipping_total'])));
        $updated = OBBP_Repository::update('quotes', [
            'subtotal' => OBBP_Repository::money($subtotal),
            'discount_total' => $discount_total,
            'tax_total' => OBBP_Repository::money($tax_total),
            'grand_total' => $grand_total,
        ], null, ['id' => $quote_id]);
        return false !== $updated;
    }

    public static function send_quote($quote_id)
    {
        global $wpdb;
        $quote = OBBP_Repository::row('quotes', $quote_id);
        if (!$quote || 'draft' !== $quote['status']) {
            return new WP_Error('obbp_quote_not_draft', __('Only draft quotes can be sent.', 'obydullah-b2b-portal'));
        }
        if ($quote['valid_until'] && $quote['valid_until'] < current_time('Y-m-d')) {
            return new WP_Error('obbp_quote_expired', __('An expired quote cannot be sent.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('START TRANSACTION');
        $rfq_id = absint($quote['rfq_id']);
        $locked_rfq = null;
        if ($rfq_id) {
            $locked_rfq = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('rfqs'), $rfq_id), ARRAY_A);
            if (!$locked_rfq || !in_array($locked_rfq['status'], ['submitted', 'under_review', 'quoted'], true)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_rfq_not_open', __('The RFQ cannot be quoted in its current state.', 'obydullah-b2b-portal'));
            }
        }
        $locked = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('quotes'), $quote_id), ARRAY_A);
        if (!$locked || 'draft' !== $locked['status'] || (int) $locked['company_id'] !== (int) $quote['company_id']) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_not_draft', __('This quote has already been sent.', 'obydullah-b2b-portal'));
        }
        if ($locked['valid_until'] && $locked['valid_until'] < current_time('Y-m-d')) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_expired', __('An expired quote cannot be sent.', 'obydullah-b2b-portal'));
        }
        $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE quote_id = %d AND deleted_at IS NULL', OBBP_Repository::table('quote_items'), $quote_id));
        if (!$count) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_empty', __('Add at least one quote item.', 'obydullah-b2b-portal'));
        }
        $updated = $wpdb->update(OBBP_Repository::table('quotes'), ['status' => 'sent', 'sent_at' => OBBP_Repository::now(), 'updated_at' => OBBP_Repository::now()], ['id' => $quote_id], ['%s', '%s', '%s']);
        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_update_failed', __('The quote could not be sent.', 'obydullah-b2b-portal'));
        }
        if ($locked_rfq) {
            $rfq_updated = $wpdb->update(OBBP_Repository::table('rfqs'), ['status' => 'quoted', 'quoted_at' => OBBP_Repository::now(), 'updated_at' => OBBP_Repository::now()], ['id' => $rfq_id], ['%s', '%s', '%s']);
            if (false === $rfq_updated) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_rfq_update_failed', __('The RFQ could not be updated.', 'obydullah-b2b-portal'));
            }
        }
        $wpdb->query('COMMIT');
        OBBP_Repository::log($locked['company_id'], 'quote', $quote_id, 'sent', 'Quote sent to customer.');
        OBBP_Mailer::quote_sent($quote_id);
        return true;
    }

    public static function mark_quote_viewed($quote_id, $user_id)
    {
        global $wpdb;
        $quote = self::company_quote($quote_id, $user_id);
        if (!$quote) {
            return false;
        }
        if ('sent' === $quote['status']) {
            $updated = $wpdb->update(
                OBBP_Repository::table('quotes'),
                [
                    'status'     => 'viewed',
                    'viewed_at'  => OBBP_Repository::now(),
                    'updated_at' => OBBP_Repository::now(),
                ],
                [
                    'id'          => $quote_id,
                    'company_id'  => $quote['company_id'],
                    'status'      => 'sent',
                    'deleted_at'  => null,
                ],
                ['%s', '%s', '%s'],
                ['%d', '%d', '%s', '<null>']
            );
            if (false === $updated || 0 === $updated) {
                return true;
            }
            OBBP_Repository::log($quote['company_id'], 'quote', $quote_id, 'viewed', 'Quote viewed by customer.');
        }
        return true;
    }

    public static function accept_quote($quote_id, $user_id, $accepted_quantities = [])
    {
        global $wpdb;
        $company = OBBP_Repository::company_for_user($user_id);
        if (!$company || !user_can($user_id, 'obbp_accept_quotes') || !self::can_approve_quote($quote_id, $user_id)) {
            return new WP_Error('obbp_quote_forbidden', __('You are not allowed to accept this quote.', 'obydullah-b2b-portal'));
        }
        $quote_preview = OBBP_Repository::row('quotes', $quote_id);
        if (!$company || !$quote_preview || (int) $quote_preview['company_id'] !== (int) $company['id']) {
            return new WP_Error('obbp_quote_forbidden', __('You are not allowed to accept this quote.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('START TRANSACTION');
        $rfq_id = absint($quote_preview['rfq_id']);
        $rfq = null;
        if ($rfq_id) {
            $rfq = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('rfqs'), $rfq_id), ARRAY_A);
            if (!$rfq || 'quoted' !== $rfq['status']) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_rfq_not_open', __('This RFQ is no longer open for quote acceptance.', 'obydullah-b2b-portal'));
            }
        }
        $quote = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND company_id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('quotes'), $quote_id, $company['id']), ARRAY_A);
        if (!$quote || !in_array($quote['status'], ['sent', 'viewed'], true) || (int) $quote['rfq_id'] !== $rfq_id) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_not_open', __('This quote is not open for acceptance.', 'obydullah-b2b-portal'));
        }
        if ($quote['valid_until'] && $quote['valid_until'] < current_time('Y-m-d')) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_expired', __('This quote has expired.', 'obydullah-b2b-portal'));
        }
        if (!$rfq || 'quoted' !== $rfq['status']) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_rfq_not_open', __('This RFQ is no longer open for quote acceptance.', 'obydullah-b2b-portal'));
        }
        $other_order = $quote['rfq_id'] ? $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE rfq_id = %d AND deleted_at IS NULL LIMIT 1', OBBP_Repository::table('purchase_orders'), $quote['rfq_id'])) : null;
        if ($other_order) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_rfq_already_ordered', __('Another purchase order already exists for this RFQ.', 'obydullah-b2b-portal'));
        }
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE quote_id = %d AND deleted_at IS NULL ORDER BY id ASC', OBBP_Repository::table('quote_items'), $quote_id), ARRAY_A);
        $accepted_quantities = is_array($accepted_quantities) ? $accepted_quantities : [];
        $quantities = [];
        $partial = false;
        $accepted = false;
        foreach ($items as $item) {
            $item_key = (string) $item['id'];
            if (!array_key_exists($item_key, $accepted_quantities) && !array_key_exists((int) $item['id'], $accepted_quantities)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_invalid_quantity', __('Enter a quantity for every quote item.', 'obydullah-b2b-portal'));
            }
            $raw_quantity = array_key_exists($item_key, $accepted_quantities) ? $accepted_quantities[$item_key] : $accepted_quantities[(int) $item['id']];
            if (!is_numeric($raw_quantity) || !is_finite((float) $raw_quantity)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_invalid_quantity', __('Enter valid accepted quantities for every item.', 'obydullah-b2b-portal'));
            }
            $quantity = OBBP_Repository::quantity($raw_quantity);
            if ($quantity < 0 || $quantity > (float) $item['quantity'] + 0.0001) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_invalid_quantity', __('Enter valid accepted quantities for every item.', 'obydullah-b2b-portal'));
            }
            $quantities[(int) $item['id']] = $quantity;
            if ($quantity > 0) {
                $accepted = true;
            }
            if ($quantity + 0.0001 < (float) $item['quantity']) {
                $partial = true;
            }
        }
        if (!$accepted) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_no_items_accepted', __('Accept at least one item.', 'obydullah-b2b-portal'));
        }
        foreach ($quantities as $item_id => $quantity) {
            if (false === $wpdb->update(OBBP_Repository::table('quote_items'), ['accepted_quantity' => $quantity], ['id' => $item_id], ['%f'])) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_quote_update_failed', __('Accepted quantities could not be saved.', 'obydullah-b2b-portal'));
            }
        }
        $status = $partial ? 'partially_accepted' : 'accepted';
        $quote_updated = $wpdb->update(OBBP_Repository::table('quotes'), [
            'status' => $status,
            'is_partial' => $partial ? 1 : 0,
            'accepted_at' => OBBP_Repository::now(),
            'accepted_by_user_id' => $user_id,
            'accepted_ip' => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
            'updated_at' => OBBP_Repository::now(),
        ], ['id' => $quote_id], ['%s', '%d', '%s', '%d', '%s', '%s']);
        if (false === $quote_updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_update_failed', __('The quote response could not be saved.', 'obydullah-b2b-portal'));
        }
        $po_id = self::create_po_from_quote_locked($quote_id);
        if (is_wp_error($po_id)) {
            $wpdb->query('ROLLBACK');
            return $po_id;
        }
        if ($quote['rfq_id']) {
            $rfq_updated = $wpdb->update(OBBP_Repository::table('rfqs'), ['status' => 'ordered', 'updated_at' => OBBP_Repository::now()], ['id' => $quote['rfq_id']], ['%s', '%s']);
            if (false === $rfq_updated) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_rfq_update_failed', __('The RFQ could not be updated.', 'obydullah-b2b-portal'));
            }
        }
        $wpdb->query('COMMIT');
        OBBP_Repository::log($company['id'], 'quote', $quote_id, 'accepted', 'Quote accepted by customer.', ['purchase_order_id' => $po_id, 'partial' => $partial]);
        OBBP_Mailer::quote_decision($quote_id, 'accepted');
        return ['quote_id' => $quote_id, 'purchase_order_id' => $po_id, 'partial' => $partial];
    }

    public static function reject_quote($quote_id, $user_id, $reason)
    {
        global $wpdb;
        $company = OBBP_Repository::company_for_user($user_id);
        $quote = self::company_quote($quote_id, $user_id);
        if (!$company || !$quote || !self::can_approve_quote($quote_id, $user_id)) {
            return new WP_Error('obbp_quote_forbidden', __('You are not allowed to reject this quote.', 'obydullah-b2b-portal'));
        }
        $reason = sanitize_textarea_field($reason);
        if ('' === trim($reason)) {
            return new WP_Error('obbp_reason_required', __('Please provide a rejection reason.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('START TRANSACTION');
        $locked = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND company_id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('quotes'), $quote_id, $company['id']), ARRAY_A);
        if (!$locked || !in_array($locked['status'], ['sent', 'viewed'], true)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_not_open', __('This quote is not open for a response.', 'obydullah-b2b-portal'));
        }
        if ($locked['valid_until'] && $locked['valid_until'] < current_time('Y-m-d')) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_expired', __('This quote has expired.', 'obydullah-b2b-portal'));
        }
        $updated = $wpdb->update(OBBP_Repository::table('quotes'), ['status' => 'rejected', 'rejected_at' => OBBP_Repository::now(), 'rejection_reason' => $reason, 'updated_at' => OBBP_Repository::now()], ['id' => $quote_id], ['%s', '%s', '%s', '%s']);
        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_quote_update_failed', __('The quote response could not be saved.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('COMMIT');
        OBBP_Repository::log($company['id'], 'quote', $quote_id, 'rejected', 'Quote rejected by customer.', ['reason' => $reason]);
        OBBP_Mailer::quote_decision($quote_id, 'rejected');
        return true;
    }

    public static function create_po_from_quote($quote_id)
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        $po_id = self::create_po_from_quote_locked($quote_id);
        if (is_wp_error($po_id)) {
            $wpdb->query('ROLLBACK');
            return $po_id;
        }
        $wpdb->query('COMMIT');
        return $po_id;
    }

    private static function create_po_from_quote_locked($quote_id)
    {
        global $wpdb;
        $quote = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('quotes'), $quote_id), ARRAY_A);
        if (!$quote || !in_array($quote['status'], ['accepted', 'partially_accepted'], true)) {
            return new WP_Error('obbp_quote_not_accepted', __('The quote is not accepted.', 'obydullah-b2b-portal'));
        }
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE quote_id = %d LIMIT 1', OBBP_Repository::table('purchase_orders'), $quote_id));
        if ($existing) {
            return new WP_Error('obbp_po_exists', __('A purchase order already exists for this quote.', 'obydullah-b2b-portal'));
        }
        $company = OBBP_Repository::row('companies', $quote['company_id']);
        if (!$company) {
            return new WP_Error('obbp_company_missing', __('Company not found.', 'obydullah-b2b-portal'));
        }
        $po_id = OBBP_Repository::insert('purchase_orders', [
            'po_number' => 'TMP-' . wp_generate_uuid4(),
            'company_id' => $quote['company_id'],
            'quote_id' => $quote_id,
            'rfq_id' => $quote['rfq_id'],
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'currency' => $quote['currency'],
            'grand_total' => $quote['grand_total'],
            'ship_to_name' => $company['name'],
            'ship_to_address' => self::formatted_address($company),
        ]);
        if (!$po_id) {
            return new WP_Error('obbp_po_create_failed', __('The purchase order could not be created.', 'obydullah-b2b-portal'));
        }
        $reference_updated = OBBP_Repository::update('purchase_orders', ['po_number' => OBBP_Repository::reference('PO', $po_id)], ['%s'], ['id' => $po_id]);
        if (false === $reference_updated) {
            return new WP_Error('obbp_po_create_failed', __('The purchase order reference could not be created.', 'obydullah-b2b-portal'));
        }
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE quote_id = %d AND deleted_at IS NULL ORDER BY line_sort ASC, id ASC', OBBP_Repository::table('quote_items'), $quote_id), ARRAY_A);
        $po_subtotal = 0.0;
        $po_tax = 0.0;
        $inserted = 0;
        foreach ($items as $item) {
            $quantity = null === $item['accepted_quantity'] ? $item['quantity'] : $item['accepted_quantity'];
            if ((float) $quantity <= 0) {
                continue;
            }
            $gross = OBBP_Repository::money((float) $quantity * (float) $item['unit_price']);
            $quoted_quantity = (float) $item['quantity'];
            $line_discount = $quoted_quantity > 0 ? min($gross, OBBP_Repository::money((float) $item['line_discount'] * ((float) $quantity / $quoted_quantity))) : 0;
            $line_total = max(0, OBBP_Repository::money($gross - $line_discount));
            $line_tax = max(0, OBBP_Repository::money($line_total * (max(0, (float) $item['tax_rate']) / 100)));
            $po_subtotal += $line_total;
            $po_tax += $line_tax;
            $line_id = OBBP_Repository::insert('purchase_order_items', [
                'purchase_order_id' => $po_id,
                'quote_item_id' => $item['id'],
                'rfq_item_id' => $item['rfq_item_id'],
                'product_id' => $item['product_id'],
                'sku' => $item['sku'],
                'name' => $item['name'],
                'description' => $item['description'],
                'unit' => $item['unit'],
                'quantity' => $quantity,
                'unit_price' => $item['unit_price'],
                'tax_rate' => $item['tax_rate'],
                'tax_amount' => $line_tax,
                'line_total' => $line_total,
                'line_tax_total' => $line_tax,
                'lead_time_days' => $item['lead_time_days'],
                'note' => $item['note'],
                'line_sort' => $item['line_sort'],
            ]);
            if (!$line_id) {
                return new WP_Error('obbp_po_item_failed', __('A purchase order item could not be saved.', 'obydullah-b2b-portal'));
            }
            $inserted++;
        }
        if (!$inserted) {
            return new WP_Error('obbp_po_empty', __('The accepted quote has no purchasable items.', 'obydullah-b2b-portal'));
        }
        $ratio = (float) $quote['subtotal'] > 0 ? $po_subtotal / (float) $quote['subtotal'] : 1;
        $allocated_discount = min($po_subtotal, max(0, OBBP_Repository::money((float) $quote['discount_total'] * $ratio)));
        $po_total = max(0, OBBP_Repository::money($po_subtotal - $allocated_discount + $po_tax + max(0, (float) $quote['shipping_total'])));
        $updated = OBBP_Repository::update('purchase_orders', ['grand_total' => $po_total], ['%f'], ['id' => $po_id]);
        if (false === $updated) {
            return new WP_Error('obbp_po_update_failed', __('The purchase order total could not be saved.', 'obydullah-b2b-portal'));
        }
        OBBP_Repository::log($quote['company_id'], 'purchase_order', $po_id, 'created', 'Purchase order created from accepted quote.', ['quote_id' => $quote_id]);
        return $po_id;
    }

    public static function issue_po($po_id)
    {
        global $wpdb;
        $po = OBBP_Repository::row('purchase_orders', $po_id);
        if (!$po || 'draft' !== $po['status']) {
            return new WP_Error('obbp_po_not_draft', __('Only draft purchase orders can be issued.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('START TRANSACTION');
        $locked = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('purchase_orders'), $po_id), ARRAY_A);
        if (!$locked || 'draft' !== $locked['status']) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_po_not_draft', __('This purchase order has already been issued.', 'obydullah-b2b-portal'));
        }
        $updated = $wpdb->update(OBBP_Repository::table('purchase_orders'), ['status' => 'issued', 'issued_at' => OBBP_Repository::now(), 'issued_by' => get_current_user_id(), 'updated_at' => OBBP_Repository::now()], ['id' => $po_id], ['%s', '%s', '%d', '%s']);
        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_po_update_failed', __('The purchase order could not be issued.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('COMMIT');
        OBBP_Repository::log($locked['company_id'], 'purchase_order', $po_id, 'issued', 'Purchase order issued to customer.');
        OBBP_Mailer::po_issued($po_id);
        return true;
    }

    public static function acknowledge_po($po_id, $user_id)
    {
        global $wpdb;
        $company = OBBP_Repository::company_for_user($user_id);
        $po = OBBP_Repository::row('purchase_orders', $po_id);
        if (!$company || !$po || (int) $po['company_id'] !== (int) $company['id'] || 'issued' !== $po['status']) {
            return new WP_Error('obbp_po_unavailable', __('This purchase order cannot be acknowledged.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('START TRANSACTION');
        $locked = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND company_id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('purchase_orders'), $po_id, $company['id']), ARRAY_A);
        if (!$locked || 'issued' !== $locked['status']) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_po_unavailable', __('This purchase order cannot be acknowledged.', 'obydullah-b2b-portal'));
        }
        $updated = $wpdb->update(OBBP_Repository::table('purchase_orders'), ['status' => 'acknowledged', 'acknowledged_at' => OBBP_Repository::now(), 'updated_at' => OBBP_Repository::now()], ['id' => $po_id], ['%s', '%s', '%s']);
        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_po_update_failed', __('The purchase order could not be acknowledged.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('COMMIT');
        OBBP_Repository::log($company['id'], 'purchase_order', $po_id, 'acknowledged', 'Purchase order acknowledged by customer.');
        return true;
    }

    public static function transition_po($po_id, $status)
    {
        global $wpdb;
        $map = [
            'draft' => ['cancelled'],
            'issued' => ['confirmed', 'acknowledged', 'cancelled'],
            'acknowledged' => ['confirmed', 'in_production', 'cancelled'],
            'confirmed' => ['in_production', 'cancelled'],
            'in_production' => ['dispatched', 'cancelled'],
            'dispatched' => ['delivered', 'cancelled'],
            'delivered' => ['completed'],
            'completed' => ['closed'],
        ];
        $po = OBBP_Repository::row('purchase_orders', $po_id);
        if (!$po || !isset($map[$po['status']]) || !in_array($status, $map[$po['status']], true)) {
            return new WP_Error('obbp_invalid_status', __('That purchase order transition is not allowed.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('START TRANSACTION');
        $locked = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('purchase_orders'), $po_id), ARRAY_A);
        if (!$locked || !isset($map[$locked['status']]) || !in_array($status, $map[$locked['status']], true)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_invalid_status', __('That purchase order transition is no longer allowed.', 'obydullah-b2b-portal'));
        }
        $extra = match ($status) {
            'dispatched' => ['dispatched_at' => OBBP_Repository::now()],
            'delivered' => ['delivered_date' => current_time('Y-m-d')],
            'completed' => ['completed_at' => OBBP_Repository::now()],
            'cancelled' => ['cancelled_at' => OBBP_Repository::now()],
            default => [],
        };
        $data = ['status' => $status, 'updated_at' => OBBP_Repository::now()] + $extra;
        $fields = array_fill_keys(array_keys($data), '%s');
        $updated = $wpdb->update(OBBP_Repository::table('purchase_orders'), $data, ['id' => $po_id], $fields);
        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_po_update_failed', __('The purchase order status could not be saved.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('COMMIT');
        OBBP_Repository::log($locked['company_id'], 'purchase_order', $po_id, 'status_' . $status, sprintf('Purchase order status changed to %s.', $status));
        return true;
    }

    public static function create_reorder($rfq_id, $user_id)
    {
        global $wpdb;
        $source = self::company_rfq($rfq_id, $user_id);
        if (!$source) {
            return new WP_Error('obbp_rfq_missing', __('RFQ not found.', 'obydullah-b2b-portal'));
        }
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE rfq_id = %d AND deleted_at IS NULL ORDER BY line_sort ASC, id ASC', OBBP_Repository::table('rfq_items'), $rfq_id), ARRAY_A);
        $items = array_values(array_filter($items, function ($item) {
            $product = get_post($item['product_id']);
            return $product && 'obbp_product' === $product->post_type && 'publish' === $product->post_status && get_post_meta($item['product_id'], '_obbp_portal_visible', true) && 'discontinued' !== get_post_meta($item['product_id'], '_obbp_availability', true);
        }));
        if (!$items) {
            return new WP_Error('obbp_reorder_unavailable', __('None of the previous items are currently available.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('START TRANSACTION');
        $new_id = self::create_rfq($source['company_id'], $user_id, source: 'portal', parent_rfq_id: $rfq_id);
        if (is_wp_error($new_id)) {
            $wpdb->query('ROLLBACK');
            return $new_id;
        }
        foreach ($items as $item) {
            $item_id = OBBP_Repository::insert('rfq_items', [
                'rfq_id' => $new_id,
                'product_id' => $item['product_id'],
                'sku' => $item['sku'],
                'name' => $item['name'],
                'description' => $item['description'],
                'unit' => $item['unit'],
                'quantity' => $item['quantity'],
                'target_price' => $item['target_price'],
                'note' => $item['note'],
                'line_sort' => $item['line_sort'],
            ]);
            if (!$item_id) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_insert_failed', __('A reordered item could not be added.', 'obydullah-b2b-portal'));
            }
        }
        self::refresh_rfq_item_count($new_id);
        $wpdb->query('COMMIT');
        OBBP_Repository::log($source['company_id'], 'rfq', $new_id, 'reordered', 'RFQ created from previous order.', ['source_rfq_id' => $rfq_id]);
        return $new_id;
    }

    public static function transition_rfq($rfq_id, $status, $reason = '')
    {
        global $wpdb;
        $map = [
            'submitted' => ['under_review', 'rejected', 'cancelled', 'closed'],
            'under_review' => ['rejected', 'cancelled', 'closed'],
            'quoted' => ['rejected', 'cancelled', 'closed'],
            'ordered' => ['closed'],
        ];
        $rfq = OBBP_Repository::row('rfqs', $rfq_id);
        if (!$rfq || !isset($map[$rfq['status']]) || !in_array($status, $map[$rfq['status']], true)) {
            return new WP_Error('obbp_invalid_status', __('That RFQ transition is not allowed.', 'obydullah-b2b-portal'));
        }
        $reason = sanitize_textarea_field($reason);
        if (in_array($status, ['rejected', 'cancelled'], true) && '' === trim($reason)) {
            return new WP_Error('obbp_reason_required', __('Please provide a reason.', 'obydullah-b2b-portal'));
        }
        $wpdb->query('START TRANSACTION');
        $locked = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE', OBBP_Repository::table('rfqs'), $rfq_id), ARRAY_A);
        if (!$locked || !isset($map[$locked['status']]) || !in_array($status, $map[$locked['status']], true)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_invalid_status', __('That RFQ transition is no longer allowed.', 'obydullah-b2b-portal'));
        }
        $extra = match ($status) {
            'closed' => ['closed_at' => OBBP_Repository::now()],
            'quoted' => ['quoted_at' => OBBP_Repository::now()],
            'cancelled' => ['cancelled_at' => OBBP_Repository::now(), 'cancelled_reason' => $reason],
            default => [],
        };
        $data = ['status' => $status, 'updated_at' => OBBP_Repository::now()] + $extra;
        $fields = array_fill_keys(array_keys($data), '%s');
        $updated = $wpdb->update(OBBP_Repository::table('rfqs'), $data, ['id' => $rfq_id], $fields);
        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('obbp_rfq_update_failed', __('The RFQ status could not be saved.', 'obydullah-b2b-portal'));
        }
        if (in_array($status, ['rejected', 'cancelled', 'closed'], true)) {
            $cancellable = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE rfq_id = %d AND status IN (%s, %s, %s) AND deleted_at IS NULL', OBBP_Repository::table('quotes'), $rfq_id, 'sent', 'viewed', 'draft'));
            $quotes_updated = OBBP_Repository::update_ids('quotes', ['status' => 'cancelled', 'cancelled_at' => OBBP_Repository::now()], null, $cancellable);
            if (false === $quotes_updated) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('obbp_quote_update_failed', __('Open quotes could not be cancelled.', 'obydullah-b2b-portal'));
            }
        }
        $wpdb->query('COMMIT');
        OBBP_Repository::log($locked['company_id'], 'rfq', $rfq_id, 'status_' . $status, sprintf('RFQ status changed to %s.', $status), ['reason' => $reason]);
        return true;
    }

    public static function expire_quotes()
    {
        global $wpdb;
        $expiring = $wpdb->get_results($wpdb->prepare("SELECT id, status FROM %i WHERE status IN ('sent','viewed') AND valid_until IS NOT NULL AND valid_until < %s AND deleted_at IS NULL", OBBP_Repository::table('quotes'), current_time('Y-m-d')), ARRAY_A);
        foreach ($expiring as $expired) {
            // The status travels into the WHERE clause so a row that changed
            // state after the SELECT still matches zero rows and is skipped.
            $updated = $wpdb->update(OBBP_Repository::table('quotes'), ['status' => 'expired', 'expired_at' => OBBP_Repository::now(), 'updated_at' => OBBP_Repository::now()], ['id' => (int) $expired['id'], 'status' => $expired['status']], ['%s', '%s', '%s']);
            if (false === $updated || 0 === $updated) {
                continue;
            }
            $quote = OBBP_Repository::row('quotes', $expired['id']);
            if ($quote) {
                OBBP_Repository::log($quote['company_id'], 'quote', $expired['id'], 'expired', 'Quote expired automatically.');
            }
        }
    }

    public static function can_approve_quote($quote_id, $user_id)
    {
        global $wpdb;
        $quote = OBBP_Repository::row('quotes', $quote_id);
        $company = OBBP_Repository::company_for_user($user_id);
        if (!$quote || !$company) {
            return false;
        }
        if ((int) $quote['company_id'] === (int) $company['id'] && (int) $company['user_id'] === (int) $user_id) {
            return true;
        }
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE company_id = %d AND user_id = %d AND can_approve_quotes = 1 AND status = 'active' AND deleted_at IS NULL LIMIT 1", OBBP_Repository::table('company_contacts'), $quote['company_id'], $user_id));
    }

    private static function provision_portal_user($company, $contact)
    {
        global $wpdb;
        $created_user = false;
        $user_id = absint($contact['user_id']);
        if ($user_id && !get_userdata($user_id)) {
            $user_id = 0;
        }
        if (!$user_id) {
            $user = get_user_by('email', $company['email']);
            if (!$user) {
                $user = get_user_by('email', $contact['email']);
            }
            if ($user) {
                $user_id = $user->ID;
            }
        }
        if ($user_id) {
            $other = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE user_id = %d AND id != %d AND deleted_at IS NULL LIMIT 1', OBBP_Repository::table('companies'), $user_id, $company['id']));
            $other_contact = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE user_id = %d AND company_id != %d AND deleted_at IS NULL LIMIT 1', OBBP_Repository::table('company_contacts'), $user_id, $company['id']));
            if ($other || $other_contact) {
                return new WP_Error('obbp_user_already_linked', __('This contact is already linked to another company.', 'obydullah-b2b-portal'));
            }
        }
        if (!$user_id) {
            $username = 'obbp-' . substr(md5(strtolower($company['email']) . wp_generate_password(8, false)), 0, 14);
            $user_id = wp_insert_user([
                'user_login' => $username,
                'user_email' => sanitize_email($company['email']),
                'user_pass' => wp_generate_password(32, true, true),
                'display_name' => $company['name'],
                'first_name' => sanitize_text_field($contact['full_name']),
                'role' => 'obbp_customer',
            ]);
            if (is_wp_error($user_id) || !$user_id) {
                return is_wp_error($user_id) ? $user_id : new WP_Error('obbp_user_create_failed', __('The portal account could not be created.', 'obydullah-b2b-portal'));
            }
            $created_user = true;
            update_user_meta($user_id, '_obbp_created_by_plugin', 1);
        } else {
            $user = new WP_User($user_id);
            $user->add_role('obbp_customer');
        }
        update_user_meta($user_id, '_obbp_company_id', $company['id']);
        $contact_updated = OBBP_Repository::update('company_contacts', ['user_id' => $user_id], ['%d'], ['id' => $contact['id']]);
        if (false === $contact_updated) {
            $error = new WP_Error('obbp_update_failed', __('The company contact could not be linked to its portal account.', 'obydullah-b2b-portal'));
            if ($created_user) {
                $error->add_data(['created_user_id' => $user_id]);
            }
            return $error;
        }
        return ['user_id' => $user_id, 'created_user_id' => $created_user ? $user_id : 0];
    }

    private static function contact_id($company_id, $user_id)
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE company_id = %d AND user_id = %d AND status = 'active' AND deleted_at IS NULL LIMIT 1", OBBP_Repository::table('company_contacts'), $company_id, $user_id));
    }

    public static function company_rfq($rfq_id, $user_id)
    {
        $rfq = OBBP_Repository::row('rfqs', $rfq_id);
        $company = $rfq ? OBBP_Repository::row('companies', $rfq['company_id']) : null;
        return $rfq && $company && 'approved' === $company['status'] && OBBP_Repository::company_owns_user($rfq['company_id'], $user_id) ? $rfq : null;
    }

    public static function company_quote($quote_id, $user_id)
    {
        $quote = OBBP_Repository::row('quotes', $quote_id);
        $company = $quote ? OBBP_Repository::row('companies', $quote['company_id']) : null;
        return $quote && $company && 'approved' === $company['status'] && !in_array($quote['status'], ['draft', 'superseded'], true) && OBBP_Repository::company_owns_user($quote['company_id'], $user_id) ? $quote : null;
    }

    public static function company_po($po_id, $user_id)
    {
        $po = OBBP_Repository::row('purchase_orders', $po_id);
        $company = $po ? OBBP_Repository::row('companies', $po['company_id']) : null;
        return $po && $company && 'approved' === $company['status'] && 'draft' !== $po['status'] && OBBP_Repository::company_owns_user($po['company_id'], $user_id) ? $po : null;
    }

    private static function refresh_rfq_item_count($rfq_id)
    {
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE rfq_id = %d AND deleted_at IS NULL', OBBP_Repository::table('rfq_items'), $rfq_id));
        OBBP_Repository::update('rfqs', ['item_count' => $count], ['%d'], ['id' => $rfq_id]);
    }

    private static function validate_quantity($quantity, $minimum, $step)
    {
        if (!is_numeric($quantity) || !is_finite((float) $quantity) || !is_numeric($minimum) || !is_finite((float) $minimum) || !is_numeric($step) || !is_finite((float) $step)) {
            return new WP_Error('obbp_invalid_quantity', __('Enter a valid quantity.', 'obydullah-b2b-portal'));
        }
        $quantity = (float) $quantity;
        $minimum = max(0, (float) $minimum);
        $step = max(0, (float) $step);
        if ($quantity <= 0 || $quantity < $minimum) {
            /* translators: %s: the minimum order quantity for this product. */
            return new WP_Error('obbp_invalid_quantity', sprintf(__('The minimum quantity is %s.', 'obydullah-b2b-portal'), OBBP_Repository::quantity($minimum)));
        }
        if ((float) $step > 0) {
            $remainder = fmod($quantity - (float) $minimum, (float) $step);
            if (abs($remainder) > 0.00001 && abs($remainder - (float) $step) > 0.00001) {
                /* translators: %s: the quantity increment this product must be ordered in. */
                return new WP_Error('obbp_invalid_quantity', sprintf(__('Use increments of %s.', 'obydullah-b2b-portal'), OBBP_Repository::quantity($step)));
            }
        }
        return true;
    }

    private static function site_date_offset($days)
    {
        return gmdate('Y-m-d', current_time('timestamp') + (int) $days * DAY_IN_SECONDS);
    }

    private static function valid_date($date)
    {
        if (!$date) {
            return false;
        }
        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private static function company_currency($company)
    {
        if (!empty($company['currency'])) {
            return strtoupper($company['currency']);
        }
        $settings = get_option(OBBP_OPTION_SETTINGS, []);
        return strtoupper($settings['default_currency'] ?? 'BDT');
    }

    public static function formatted_address($company)
    {
        return implode(', ', array_filter([$company['address_line1'], $company['address_line2'], $company['city'], $company['state'], $company['postcode'], $company['country']]));
    }
}
