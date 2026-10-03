<?php
if (!defined('ABSPATH')) {
    exit;
}

final class OBBP_Mailer
{
    public static function company_status($company_id, $status)
    {
        $company = OBBP_Repository::row('companies', $company_id);
        if (!$company || !$company['email_enabled']) {
            return false;
        }
        $subject = match ($status) {
            'approved' => __('Your B2B account has been approved', 'obydullah-b2b-portal'),
            default => __('Update to your B2B account', 'obydullah-b2b-portal'),
        };
        $message = match ($status) {
            'approved' => __('Your company account is approved. You can now sign in and submit RFQs.', 'obydullah-b2b-portal'),
            /* translators: 1: company account status, 2: note explaining the status change. */
            default => sprintf(__('Your company account status is %1$s. %2$s', 'obydullah-b2b-portal'), $status, $company['status_note']),
        };
        return self::send($company['email'], $subject, self::paragraphs($message, $company), $company_id, 'company', $company_id, 'company_status_' . $status);
    }

    public static function rfq_submitted($rfq_id)
    {
        $rfq = OBBP_Repository::row('rfqs', $rfq_id);
        $company = $rfq ? OBBP_Repository::row('companies', $rfq['company_id']) : null;
        if (!$rfq || !$company) {
            return false;
        }
        /* translators: 1: company name, 2: RFQ reference number, 3: number of line items. */
        $message = sprintf(__('%1$s submitted RFQ %2$s with %3$d items.', 'obydullah-b2b-portal'), $company['name'], $rfq['rfq_number'], $rfq['item_count']);
        $recipients = apply_filters('obbp_admin_email_recipients', [get_option('admin_email')]);
        $recipients = array_values(array_unique(array_filter(array_map('sanitize_email', (array) $recipients))));
        $success = true;
        foreach ($recipients as $recipient) {
            /* translators: %s: RFQ reference number. */
            $success = self::send($recipient, sprintf(__('New RFQ: %s', 'obydullah-b2b-portal'), $rfq['rfq_number']), self::paragraphs($message, $company), $company['id'], 'rfq', $rfq_id, 'rfq_submitted') && $success;
        }
        return $success;
    }

    public static function quote_sent($quote_id)
    {
        $quote = OBBP_Repository::row('quotes', $quote_id);
        if (!$quote) {
            return false;
        }
        $success = true;
        foreach (self::customer_recipients($quote['company_id']) as $recipient) {
            /* translators: 1: quotation reference number, 2: last date the quotation is valid. */
            $message = sprintf(__('Quote %1$s is available in your B2B portal. Valid until %2$s.', 'obydullah-b2b-portal'), $quote['quote_number'], $quote['valid_until']);
            /* translators: %s: quotation reference number. */
            $success = self::send($recipient, sprintf(__('New quote: %s', 'obydullah-b2b-portal'), $quote['quote_number']), self::paragraphs($message, OBBP_Repository::row('companies', $quote['company_id'])), $quote['company_id'], 'quote', $quote_id, 'quote_sent') && $success;
        }
        return $success;
    }

    public static function quote_decision($quote_id, $decision)
    {
        $quote = OBBP_Repository::row('quotes', $quote_id);
        if (!$quote) {
            return false;
        }
        $message = match ($decision) {
            /* translators: %s: quotation reference number. */
            'accepted' => sprintf(__('Quote %s was accepted. A draft purchase order has been created.', 'obydullah-b2b-portal'), $quote['quote_number']),
            /* translators: 1: quotation reference number, 2: reason given by the customer. */
            default => sprintf(__('Quote %1$s was rejected. Reason: %2$s', 'obydullah-b2b-portal'), $quote['quote_number'], $quote['rejection_reason']),
        };
        $recipients = apply_filters('obbp_admin_email_recipients', [get_option('admin_email')]);
        $success = true;
        foreach ((array) $recipients as $recipient) {
            /* translators: 1: quotation reference number, 2: the customer's decision, either accepted or rejected. */
            $success = self::send(sanitize_email($recipient), sprintf(__('Quote %1$s: %2$s', 'obydullah-b2b-portal'), $quote['quote_number'], ucfirst($decision)), self::paragraphs($message, OBBP_Repository::row('companies', $quote['company_id'])), $quote['company_id'], 'quote', $quote_id, 'quote_' . $decision) && $success;
        }
        return $success;
    }

    public static function po_issued($po_id)
    {
        $po = OBBP_Repository::row('purchase_orders', $po_id);
        if (!$po) {
            return false;
        }
        $company = OBBP_Repository::row('companies', $po['company_id']);
        /* translators: 1: purchase order reference number, 2: order grand total, 3: currency code. */
        $message = sprintf(__('Purchase order %1$s has been issued. Total: %2$s %3$s.', 'obydullah-b2b-portal'), $po['po_number'], number_format_i18n((float) $po['grand_total'], 2), $po['currency']);
        $success = true;
        foreach (self::customer_recipients($po['company_id']) as $recipient) {
            /* translators: %s: purchase order reference number. */
            $success = self::send($recipient, sprintf(__('Purchase order: %s', 'obydullah-b2b-portal'), $po['po_number']), self::paragraphs($message, $company), $po['company_id'], 'purchase_order', $po_id, 'po_issued') && $success;
        }
        return $success;
    }

    private static function send($to, $subject, $message, $company_id, $object_type, $object_id, $action)
    {
        $settings = get_option(OBBP_OPTION_SETTINGS, []);
        if (empty($settings['email_enabled']) || !is_email($to)) {
            return false;
        }
        if ($company_id) {
            $company = OBBP_Repository::row('companies', $company_id);
            if (!$company || empty($company['email_enabled'])) {
                return false;
            }
        }
        $from_name = !empty($settings['email_from_name']) ? $settings['email_from_name'] : get_bloginfo('name');
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if (get_bloginfo('admin_email')) {
            $headers[] = 'From: ' . wp_specialchars_decode($from_name, ENT_QUOTES) . ' <' . get_bloginfo('admin_email') . '>';
        }
        $sent = wp_mail($to, wp_specialchars_decode($subject), $message, $headers);
        OBBP_Repository::log($company_id, $object_type, $object_id, $action, $sent ? sprintf('Email sent to %s.', $to) : sprintf('Email delivery failed for %s.', $to), ['recipient' => sanitize_email($to)], $sent ? 'info' : 'warning');
        return $sent;
    }

    private static function customer_recipients($company_id)
    {
        global $wpdb;
        $emails = $wpdb->get_col($wpdb->prepare("SELECT email FROM %i WHERE company_id = %d AND status = 'active' AND deleted_at IS NULL", OBBP_Repository::table('company_contacts'), $company_id));
        return array_values(array_unique(array_filter(array_map('sanitize_email', $emails))));
    }

    private static function paragraphs($message, $company)
    {
        $site = get_bloginfo('name');
        $text = wpautop(esc_html($message));
        return '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#172033;line-height:1.6"><div style="max-width:640px;margin:auto;padding:24px"><h2 style="margin-top:0">' . esc_html($site) . '</h2>' . $text . '<p style="color:#667085;font-size:13px">' . esc_html($company['name'] ?? '') . '</p></div></body></html>';
    }
}
