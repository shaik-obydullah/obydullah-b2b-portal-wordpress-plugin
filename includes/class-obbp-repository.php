<?php
if (!defined('ABSPATH')) {
    exit;
}

// This is the plugin's single data-access layer for its own obbp_* tables.
// Reads that benefit from caching go through cache_get()/cache_set() below;
// writes (insert/update/delete) are inherently uncached. All values are bound
// via $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

final class OBBP_Repository
{
    private const CACHE_GROUP = 'obbp';

    /** Freshness backstop for derived rows (pricing, price lists, facets). */
    private const CACHE_TTL_STABLE = 900;

    /** Dashboard counters are allowed to lag by up to a minute. */
    private const CACHE_TTL_COUNTS = 60;

    /** Safety net for membership lookups in case a flush site is ever missed. */
    private const CACHE_TTL_ACCESS = 300;

    private static string $request_id = '';

    public static function cache_get($key)
    {
        return wp_cache_get($key, self::CACHE_GROUP);
    }

    public static function cache_set($key, $value, $ttl = 0)
    {
        wp_cache_set($key, $value, self::CACHE_GROUP, $ttl);
    }

    public static function cache_delete($key)
    {
        wp_cache_delete($key, self::CACHE_GROUP);
    }

    private static function revision($key)
    {
        $revision = (int) self::cache_get($key);
        return $revision > 0 ? $revision : 1;
    }

    private static function bump_revision($key)
    {
        self::cache_set($key, self::revision($key) + 1);
    }

    /**
     * Drops every cached membership lookup that depends on a company.
     *
     * Runs on company/contact writes (rare) so the portal access checks never
     * serve stale records after an approve, suspend, or link change. Enumerates
     * the company owner plus every linked contact, and also clears any ids
     * passed in $extra_user_ids (e.g. the user a contact used to point at).
     */
    public static function flush_company_access($company_id, $extra_user_ids = [])
    {
        global $wpdb;
        $company_id = absint($company_id);
        $user_ids = array_map('absint', (array) $extra_user_ids);
        $owner = (int) $wpdb->get_var($wpdb->prepare('SELECT user_id FROM %i WHERE id = %d LIMIT 1', self::table('companies'), $company_id));
        if ($owner) {
            $user_ids[] = $owner;
        }
        $contact_user_ids = $wpdb->get_col($wpdb->prepare('SELECT user_id FROM %i WHERE company_id = %d AND user_id > 0', self::table('company_contacts'), $company_id));
        foreach ($contact_user_ids as $user_id) {
            $user_ids[] = (int) $user_id;
        }
        foreach (array_unique($user_ids) as $user_id) {
            if ($user_id > 0) {
                self::cache_delete('cfu:' . $user_id);
                self::cache_delete('owns:' . $company_id . ':' . $user_id);
            }
        }
    }

    /** Invalidates the cached price for a single product after meta or items change. */
    public static function flush_product_prices($product_id)
    {
        self::bump_revision('price_prod_rev:' . absint($product_id));
    }

    /** Invalidates pricing decisions that depend on the price lists themselves. */
    public static function flush_price_list($list_id)
    {
        self::cache_delete('default_price_list');
        self::bump_revision('lists_rev');
    }

    /** Invalidates pricing for every product priced in a list whose items changed. */
    public static function flush_price_list_items($list_id)
    {
        global $wpdb;
        $product_ids = $wpdb->get_col($wpdb->prepare('SELECT product_id FROM %i WHERE price_list_id = %d', self::table('price_list_items'), $list_id));
        foreach ($product_ids as $product_id) {
            self::bump_revision('price_prod_rev:' . absint($product_id));
        }
    }

    /** Invalidates pricing decisions that depend on the default currency setting. */
    public static function flush_settings()
    {
        self::bump_revision('settings_rev');
    }

    public static function table($name)
    {
        global $wpdb;
        return $wpdb->prefix . 'obbp_' . sanitize_key($name);
    }

    public static function now()
    {
        return current_time('mysql');
    }

    public static function money($value)
    {
        return round((float) $value, 2);
    }

    public static function quantity($value)
    {
        return round((float) $value, 4);
    }

    public static function row($table, $id, $include_deleted = false)
    {
        global $wpdb;
        // Both branches keep a literal format string so the query stays fully prepared.
        if ($include_deleted) {
            return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d LIMIT 1', self::table($table), $id), ARRAY_A);
        }
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL LIMIT 1', self::table($table), $id), ARRAY_A);
    }

/**
     * Selects rows for an arbitrary set of column filters.
 *
 * $where is a column => value map. Columns are matched against a static
 * whitelist before they reach the SQL text, and every value is bound through
 * $wpdb::prepare(). Because the clause list and the ORDER BY column are
 * necessarily runtime values, the format string is assembled here rather than
 * being a literal, which the prepared-SQL sniffs cannot follow; both
 * NotPrepared and InterpolatedNotPrepared are suppressed for this block only.
 *
 * An unlisted column is skipped, so keep the whitelist in step with any filter
 * passed in by a caller.
 *
 * @param string $table Unprefixed table name.
 * @param array  $where Column => scalar|array|null filters.
 * @param array  $args  orderby, order, limit, offset, include_deleted.
 * @return array Rows keyed by column name.
 */
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql and $order_by_sql hold only whitelisted identifiers; every value in $params is a bound placeholder argument.
    public static function rows($table, $where = [], $args = [])
    {
        global $wpdb;
        $allowed_columns = ['id', 'name', 'status', 'company_id', 'is_active', 'is_default', 'currency', 'rfq_id', 'quote_id', 'purchase_order_id', 'product_id', 'user_id', 'object_type', 'object_id', 'action', 'severity', 'valid_from', 'valid_to', 'created_at', 'deleted_at'];

        $clauses = [];
        $params = [];
        foreach ($where as $column => $value) {
            if (!in_array($column, $allowed_columns, true)) {
                continue;
            }
            $column_sql = "`{$column}`";
            if (is_array($value)) {
                if (!$value) {
                    $clauses[] = '1=0';
                    continue;
                }
                $placeholders = implode(',', array_fill(0, count($value), '%d'));
                $clauses[] = "{$column_sql} IN ({$placeholders})";
                $params = array_merge($params, array_values($value));
            } elseif (is_null($value)) {
                $clauses[] = "{$column_sql} IS NULL";
            } else {
                $clauses[] = "{$column_sql} = %s";
                $params[] = $value;
            }
        }
        if (empty($args['include_deleted'])) {
            $clauses[] = '`deleted_at` IS NULL';
        }
        $where_sql = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        $orderby = $args['orderby'] ?? 'id';
        $order_by = in_array($orderby, $allowed_columns, true) ? $orderby : 'id';
        $order = 'ASC' === strtoupper($args['order'] ?? 'DESC') ? 'ASC' : 'DESC';

        $limit = min(1000, max(0, absint($args['limit'] ?? 100)));
        $offset = max(0, absint($args['offset'] ?? 0));

        $sql = "SELECT * FROM %i {$where_sql} ORDER BY `{$order_by}` {$order} LIMIT %d OFFSET %d";
        return $wpdb->get_results($wpdb->prepare($sql, array_merge([self::table($table)], $params, [$limit, $offset])), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared custom-table query
    }
    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    public static function insert($table, $data, $formats = null)
    {
        global $wpdb;
        $data['created_at'] ??= self::now();
        if (!is_array($formats)) {
            $formats = array_fill_keys(array_keys($data), '%s');
        }
        $result = $wpdb->insert(self::table($table), $data, $formats);
        return $result ? (int) $wpdb->insert_id : 0;
    }

    public static function update($table, $data, $formats, $where)
    {
        global $wpdb;
        $data['updated_at'] = self::now();
        if (!is_array($formats)) {
            $formats = array_fill_keys(array_keys($data), '%s');
        }
        return $wpdb->update(self::table($table), $data, $where, $formats);
    }

    /**
     * Applies the same update to a caller-resolved set of primary keys.
     *
     * $wpdb->update() only builds equality and IS NULL comparisons, so
     * conditions such as "id != this row" or "product_id NOT IN (...)" cannot be
     * expressed in its $where argument. Callers select the matching primary keys
     * with a prepared SELECT and pass them here, which keeps the write on the
     * prepared CRUD API instead of a hand-built UPDATE string.
     *
     * @param string     $table   Unprefixed table name.
     * @param array      $data    Column => value pairs to write.
     * @param array|null $formats Optional format list for $data. Defaults to %s.
     *                             Pass null to let every value be cast as a string.
     * @param array      $ids     Primary keys to update.
     * @return int|false Total affected rows, or false on the first failure.
     */
    public static function update_ids($table, $data, $formats, $ids)
    {
        global $wpdb;
        $ids = array_values(array_unique(array_map('absint', (array) $ids)));
        if (!$ids) {
            return 0;
        }
        if (!array_key_exists('updated_at', $data)) {
            $data['updated_at'] = self::now();
            if (is_array($formats)) {
                // A column-keyed map cannot be extended positionally, so rebuild it.
                // The list test is written out because array_is_list() needs PHP 8.1.
                $keys = array_keys($formats);
                $is_list = !$keys || $keys === range(0, count($keys) - 1);
                $formats = $is_list ? array_merge($formats, ['%s']) : $formats + ['updated_at' => '%s'];
            }
        }
        if (!is_array($formats)) {
            $formats = array_fill_keys(array_keys($data), '%s');
        }
        $total = 0;
        foreach ($ids as $id) {
            $result = $wpdb->update(self::table($table), $data, ['id' => $id], $formats);
            if (false === $result) {
                return false;
            }
            $total += (int) $result;
        }
        return $total;
    }

    public static function soft_delete($table, $id)
    {
        return self::update($table, ['deleted_at' => self::now()], ['%s'], ['id' => $id]);
    }

    public static function company_for_user($user_id)
    {
        $user_id = absint($user_id);
        $key = 'cfu:' . $user_id;
        $cached = self::cache_get($key);
        if (false !== $cached) {
            return $cached ?: null;
        }
        global $wpdb;
        $ids = [];
        $owner = $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE user_id = %d AND deleted_at IS NULL AND status = 'approved' LIMIT 1", self::table('companies'), $user_id));
        if ($owner) {
            $ids[] = (int) $owner;
        }
        $contact = $wpdb->get_var($wpdb->prepare("SELECT c.company_id FROM %i c INNER JOIN %i co ON co.id = c.company_id WHERE c.user_id = %d AND c.status = 'active' AND c.deleted_at IS NULL AND co.status = 'approved' AND co.deleted_at IS NULL LIMIT 1", self::table('company_contacts'), self::table('companies'), $user_id));
        if ($contact) {
            $ids[] = (int) $contact;
        }
        $ids = array_values(array_unique($ids));
        $company = count($ids) === 1 ? self::row('companies', $ids[0]) : null;
        self::cache_set($key, $company ?: 0, self::CACHE_TTL_ACCESS);
        return $company;
    }

    public static function can_access_portal($user_id = 0)
    {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        if (!$user_id || !user_can($user_id, 'obbp_access_portal')) {
            return false;
        }
        $company = self::company_for_user($user_id);
        return $company && 'approved' === $company['status'];
    }

    public static function company_owns_user($company_id, $user_id = 0)
    {
        $company_id = absint($company_id);
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        $key = 'owns:' . $company_id . ':' . $user_id;
        $cached = self::cache_get($key);
        if (false !== $cached) {
            return (bool) $cached;
        }
        global $wpdb;
        $companies = self::table('companies');
        $contacts = self::table('company_contacts');
        $owner = $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE id = %d AND user_id = %d AND status = 'approved' AND deleted_at IS NULL LIMIT 1", $companies, $company_id, $user_id));
        $contact = $wpdb->get_var($wpdb->prepare("SELECT c.id FROM %i c INNER JOIN %i co ON co.id = c.company_id WHERE c.company_id = %d AND c.user_id = %d AND c.status = 'active' AND c.deleted_at IS NULL AND co.status = 'approved' AND co.deleted_at IS NULL LIMIT 1", $contacts, $companies, $company_id, $user_id));
        $owns = !empty($owner) || !empty($contact);
        self::cache_set($key, $owns ? 1 : 0, self::CACHE_TTL_ACCESS);
        return $owns;
    }

    public static function price_for_product($product_id, $company)
    {
        $product_id = absint($product_id);
        $company = is_array($company) ? $company : [];
        $settings = get_option(OBBP_OPTION_SETTINGS, []);
        $company_list = absint($company['price_list_id'] ?? 0);
        $company_currency = strtoupper(trim((string) ($company['currency'] ?? '')));
        $default_currency = strtoupper((string) ($settings['default_currency'] ?? 'BDT'));

        $key = 'price:' . md5(serialize([
            'pid' => $product_id,
            'prod_rev' => self::revision('price_prod_rev:' . $product_id),
            'lists_rev' => self::revision('lists_rev'),
            'settings_rev' => self::revision('settings_rev'),
            'company_list' => $company_list,
            'company_currency' => $company_currency,
            'default_list' => self::default_price_list_id(),
            'default_currency' => $default_currency,
        ]));
        $cached = self::cache_get($key);
        if (false !== $cached) {
            return $cached;
        }

        global $wpdb;
        $company_currency = $company_currency ?: $default_currency;
        $product_currency = strtoupper(trim((string) get_post_meta($product_id, '_obbp_currency', true)));
        $currency_ok = !$product_currency || !$company_currency || $product_currency === $company_currency;
        $price = (float) get_post_meta($product_id, '_obbp_price', true);
        $min_qty = (float) get_post_meta($product_id, '_obbp_min_order_qty', true);
        $step = (float) get_post_meta($product_id, '_obbp_qty_step', true);
        $lead = absint(get_post_meta($product_id, '_obbp_lead_time_days', true));
        $list_ids = [];
        if ($company_list) {
            $list_ids[] = $company_list;
        }
        $default_list_id = self::default_price_list_id();
        if ($default_list_id && !in_array($default_list_id, $list_ids, true)) {
            $list_ids[] = $default_list_id;
        }
        foreach (array_unique($list_ids) as $candidate_id) {
            $list = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND is_active = 1 AND deleted_at IS NULL AND (valid_from IS NULL OR valid_from <= %s) AND (valid_to IS NULL OR valid_to >= %s) LIMIT 1', self::table('price_lists'), $candidate_id, current_time('Y-m-d'), current_time('Y-m-d')), ARRAY_A);
            if (!$list) {
                continue;
            }
            $list_currency = strtoupper(trim((string) $list['currency']));
            if (($list_currency && $company_currency && $list_currency !== $company_currency) || ($product_currency && $list_currency && $product_currency !== $list_currency)) {
                continue;
            }
            $item = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE price_list_id = %d AND product_id = %d AND is_active = 1 LIMIT 1', self::table('price_list_items'), $candidate_id, $product_id), ARRAY_A);
            if (!$item) {
                continue;
            }
            $price = (float) $item['unit_price'];
            $min_qty = (float) $item['min_qty'];
            $step = (float) $item['qty_step'];
            $lead = absint($item['lead_time_days']);
            if ($list_currency) {
                $company_currency = $list_currency;
            }
            break;
        }
        if (!is_finite($price) || $price < 0) {
            $price = 0;
        }
        if (!is_finite($min_qty) || $min_qty < 0) {
            $min_qty = 1;
        }
        if (!is_finite($step) || $step < 0) {
            $step = 1;
        }
        $result = [
            'price' => self::money($price),
            'min_qty' => self::quantity($min_qty ?: 1),
            'step' => self::quantity($step ?: 1),
            'lead_time_days' => $lead,
            'currency' => $product_currency ?: $company_currency,
            'currency_ok' => $currency_ok,
        ];
        self::cache_set($key, $result, self::CACHE_TTL_STABLE);
        return $result;
    }

    /**
     * Cached id of the active default price list, used as the pricing fallback.
     */
    private static function default_price_list_id()
    {
        $key = 'default_price_list';
        $cached = self::cache_get($key);
        if (false !== $cached) {
            return (int) $cached;
        }
        global $wpdb;
        $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE is_default = 1 AND is_active = 1 AND deleted_at IS NULL AND (valid_from IS NULL OR valid_from <= %s) AND (valid_to IS NULL OR valid_to >= %s) ORDER BY id ASC LIMIT 1', self::table('price_lists'), current_time('Y-m-d'), current_time('Y-m-d')));
        self::cache_set($key, $id, self::CACHE_TTL_STABLE);
        return $id;
    }

    public static function products($company, $search = '', $paged = 1, $limit = 12)
    {
        $args = [
            'post_type' => 'obbp_product',
            'post_status' => 'publish',
            'posts_per_page' => min(100, max(1, absint($limit))),
            'paged' => max(1, absint($paged)),
            's' => sanitize_text_field($search),
            'meta_query' => [
                [
                    'key' => '_obbp_portal_visible',
                    'value' => '1',
                ],
                [
                    'key' => '_obbp_availability',
                    'value' => 'discontinued',
                    'compare' => '!=',
                ],
            ],
            'orderby' => 'title',
            'order' => 'ASC',
        ];
        return new WP_Query($args);
    }

    /**
 * Admin list queries with optional filters.
 *
 * These follow the activity() pattern: an always-true seed clause, a $where array
 * of fixed SQL fragments carrying placeholders, and a parallel $params array whose
 * values are bound by $wpdb->prepare(). Only the fragment strings are joined into
 * the format string; no caller-supplied value ever reaches the SQL text, and table
 * names are bound with %i. Because the fragment list is necessarily a variable,
 * the prepared-SQL sniff cannot follow it, hence the scoped suppression below.
 *
 * Every method in this group receives already-sanitised arguments and validates
 * nothing beyond bounding its limit.
 */
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See the note above; $where holds only fixed placeholder fragments and $params is always bound.
    public static function companies_list($args = [])
    {
        global $wpdb;
        $where = [];
        $params = [];
        $companies_table = self::table('companies');
        $contacts_table = self::table('company_contacts');

        // Always exclude deleted
        $where[] = 'c.deleted_at IS NULL';

        // Status filter
        if (!empty($args['status'])) {
            $where[] = 'c.status = %s';
            $params[] = sanitize_key($args['status']);
        }

        // Search filter
        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(c.name LIKE %s OR c.email LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Limit
        $limit = min(500, max(1, absint($args['limit'] ?? 200)));

        // Final SQL (NO %i, NO interpolation tricks)
        $sql = "
            SELECT c.*,
                (
                    SELECT COUNT(*)
                    FROM {$contacts_table} ct
                    WHERE ct.company_id = c.id
                    AND ct.deleted_at IS NULL
                ) AS contact_count
            FROM {$companies_table} c
            {$where_sql}
            ORDER BY c.created_at DESC
            LIMIT %d
        ";

        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared custom-table query
    }

    public static function rfqs_list($args = [])
    {
        global $wpdb;
        $where = [];
        $params = [];
        $rfqs_table = self::table('rfqs');
        $companies_table = self::table('companies');

        // Always exclude deleted
        $where[] = 'r.deleted_at IS NULL';

        // Status filter
        if (!empty($args['status'])) {
            $where[] = 'r.status = %s';
            $params[] = sanitize_key($args['status']);
        }

        // Search filter
        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(r.subject LIKE %s OR r.rfq_number LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Limit
        $limit = min(500, max(1, absint($args['limit'] ?? 200)));

        // Final SQL (NO %i, NO interpolation tricks)
        $sql = "
            SELECT r.*, c.name company_name
            FROM {$rfqs_table} r
            INNER JOIN {$companies_table} c ON c.id = r.company_id
            {$where_sql}
            ORDER BY r.created_at DESC
            LIMIT %d
        ";

        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared custom-table query
    }

    /**
     * Returns the ids of a price list's items whose product was not submitted.
     *
     * @param int   $list_id   Price list id.
     * @param array $submitted Product ids that were submitted.
     * @return array Item ids.
     */
    public static function price_list_item_ids_missing($list_id, $submitted)
    {
        global $wpdb;
        $submitted = array_values(array_unique(array_map('absint', (array) $submitted)));
        if (!$submitted) {
            return [];
        }
        $items_table = self::table('price_list_items');
        $where = ['price_list_id = %d', 'product_id NOT IN (' . implode(',', array_fill(0, count($submitted), '%d')) . ')'];
        $where_sql = implode(' AND ', $where);
        $params = array_merge([$list_id], $submitted);

        // Final SQL (NO %i, NO interpolation tricks)
        $sql = "
            SELECT id
            FROM {$items_table}
            WHERE {$where_sql}
        ";

        // Single prepare
        return $wpdb->get_col($wpdb->prepare($sql, $params)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared custom-table query
    }

    /**
     * Rows for one CSV export entity, keyed by column name.
     *
     * @param string $entity        Export key.
     * @param int    $price_list_id Optional price list filter.
     * @return array Rows, or an empty array for an unknown entity.
     */
    public static function export_rows($entity, $price_list_id = 0)
    {
        global $wpdb;
        switch ($entity) {
            case 'companies':
                $sql = $wpdb->prepare('SELECT name,email,phone,website,tax_id,address_line1,address_line2,city,state,postcode,country,status,status_note,price_list_id,currency,payment_terms FROM %i WHERE deleted_at IS NULL ORDER BY id', self::table('companies'));
                break;
            case 'price_lists':
                $sql = $wpdb->prepare('SELECT name,slug,description,currency,is_default,is_active,valid_from,valid_to FROM %i WHERE deleted_at IS NULL ORDER BY id', self::table('price_lists'));
                break;
            case 'rfqs':
                $sql = $wpdb->prepare('SELECT r.rfq_number,c.name company,r.status,r.subject,r.notes,r.requested_delivery_date,r.item_count,r.submitted_at FROM %i r INNER JOIN %i c ON c.id=r.company_id WHERE r.deleted_at IS NULL ORDER BY r.id', self::table('rfqs'), self::table('companies'));
                break;
            case 'quotes':
                $sql = $wpdb->prepare('SELECT q.quote_number,c.name company,q.status,q.currency,q.subtotal,q.discount_total,q.tax_total,q.grand_total,q.valid_until FROM %i q INNER JOIN %i c ON c.id=q.company_id WHERE q.deleted_at IS NULL ORDER BY q.id', self::table('quotes'), self::table('companies'));
                break;
            case 'purchase_orders':
                $sql = $wpdb->prepare('SELECT p.po_number,c.name company,p.status,p.currency,p.grand_total,p.promised_ship_date FROM %i p INNER JOIN %i c ON c.id=p.company_id WHERE p.deleted_at IS NULL ORDER BY p.id', self::table('purchase_orders'), self::table('companies'));
                break;
            case 'price_list_items':
                $items_table = self::table('price_list_items');
                $postmeta_table = $wpdb->postmeta;
                $where = ['1=1'];
                $params = ['_obbp_sku'];
                if ($price_list_id) {
                    $where[] = 'i.price_list_id = %d';
                    $params[] = absint($price_list_id);
                }
                $where_sql = 'WHERE ' . implode(' AND ', $where);
                $sql = "
                    SELECT i.id, i.price_list_id, i.product_id,
                        (
                            SELECT meta_value
                            FROM {$postmeta_table}
                            WHERE post_id = i.product_id AND meta_key = %s
                            ORDER BY meta_id DESC LIMIT 1
                        ) sku,
                        i.unit_price, i.min_qty, i.qty_step, i.lead_time_days, i.is_active, i.note
                    FROM {$items_table} i
                    {$where_sql}
                    ORDER BY i.id
                ";
                $sql = $wpdb->prepare($sql, $params);
                break;
            case 'activity':
                $sql = $wpdb->prepare('SELECT created_at,user_login,actor_type,object_type,object_id,action,severity,message FROM %i ORDER BY id DESC LIMIT 10000', self::table('activity_logs'));
                break;
            default:
                return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is the output of $wpdb->prepare() in every branch above; one prepare() per entity.
        return $wpdb->get_results($sql, ARRAY_A);
    }

    public static function activity($args)
    {
        global $wpdb;
        $where = ['1=1'];
        $params = [];
        if (!empty($args['company_id'])) {
            $where[] = 'company_id = %d';
            $params[] = absint($args['company_id']);
        }
        if (!empty($args['object_type'])) {
            $where[] = 'object_type = %s';
            $params[] = sanitize_key($args['object_type']);
        }
        if (!empty($args['action'])) {
            $where[] = 'action = %s';
            $params[] = sanitize_key($args['action']);
        }
        if (!empty($args['actor_type'])) {
            $where[] = 'actor_type = %s';
            $params[] = sanitize_key($args['actor_type']);
        }
        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(message LIKE %s OR user_login LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        $limit = max(1, min(500, absint($args['limit'] ?? 100)));
        $activity_table = self::table('activity_logs');
        $where_sql = 'WHERE ' . implode(' AND ', $where);

        // Final SQL (NO %i, NO interpolation tricks)
        $sql = "
            SELECT *
            FROM {$activity_table}
            {$where_sql}
            ORDER BY created_at DESC
            LIMIT %d
        ";

        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared custom-table query
    }

    /**
     * Distinct values of one activity_logs column, for the admin filter dropdowns.
     *
     * $column is matched against a fixed whitelist, so no request value ever reaches
     * the identifier slot; %i escapes it regardless.
     */
    public static function activity_facets($column)
    {
        global $wpdb;
        $column = match ($column) {
            'object_type', 'action', 'actor_type' => $column,
            default => 'object_type',
        };
        $key = 'facets:' . $column;
        $cached = self::cache_get($key);
        if (false !== $cached) {
            return $cached;
        }
        $sql = $wpdb->prepare(
            'SELECT DISTINCT %i AS facet FROM %i WHERE %i != %s ORDER BY %i ASC',
            $column,
            self::table('activity_logs'),
            $column,
            '',
            $column
        );
        $facets = $wpdb->get_col($sql);
        self::cache_set($key, $facets, self::CACHE_TTL_COUNTS);
        return $facets;
    }
    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    /**
     * Short-lived summary counts for the admin dashboard.
     *
     * @return array Label => count. Labels carry the escape-safe text used directly
     *               by the dashboard template.
     */
    public static function admin_counts()
    {
        global $wpdb;
        $cached = self::cache_get('admin_counts');
        if (is_array($cached)) {
            return $cached;
        }
        $counts = [
            __('Pending companies', 'obydullah-b2b-portal') => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status = 'pending' AND deleted_at IS NULL", self::table('companies'))),
            __('RFQs awaiting quote', 'obydullah-b2b-portal') => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status IN ('submitted','under_review') AND deleted_at IS NULL", self::table('rfqs'))),
            __('Quotes awaiting response', 'obydullah-b2b-portal') => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status IN ('sent','viewed') AND deleted_at IS NULL", self::table('quotes'))),
            __('Open purchase orders', 'obydullah-b2b-portal') => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status NOT IN ('completed','closed','cancelled') AND deleted_at IS NULL", self::table('purchase_orders'))),
        ];
        self::cache_set('admin_counts', $counts, self::CACHE_TTL_COUNTS);
        return $counts;
    }

    /**
     * Short-lived summary counts for one company's portal dashboard.
     *
     * @return array rfq/quote/order => count.
     */
    public static function portal_counts($company_id)
    {
        global $wpdb;
        $company_id = absint($company_id);
        $key = 'portal_counts:' . $company_id;
        $cached = self::cache_get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $counts = [
            'rfq' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE company_id = %d AND deleted_at IS NULL", self::table('rfqs'), $company_id)),
            'quote' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE company_id = %d AND deleted_at IS NULL", self::table('quotes'), $company_id)),
            'order' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE company_id = %d AND status != 'draft' AND deleted_at IS NULL", self::table('purchase_orders'), $company_id)),
        ];
        self::cache_set($key, $counts, self::CACHE_TTL_COUNTS);
        return $counts;
    }

    public static function log($company_id, $object_type, $object_id, $action, $message, $context = [], $severity = 'info')
    {
        global $wpdb;
        $user_id = get_current_user_id();
        if (!$user_id) {
            $actor_type = 'system';
        } elseif (user_can($user_id, 'obbp_manage_companies')) {
            $actor_type = 'admin';
        } else {
            $actor_type = 'customer';
        }
        if (!self::$request_id) {
            self::$request_id = str_replace('-', '', wp_generate_uuid4());
        }
        $user = $user_id ? get_userdata($user_id) : null;
        $wpdb->insert(
            self::table('activity_logs'),
            [
                'company_id' => $company_id ? absint($company_id) : null,
                'user_id' => $user_id,
                'user_login' => $user ? $user->user_login : '',
                'actor_type' => $actor_type,
                'object_type' => sanitize_key($object_type),
                'object_id' => absint($object_id),
                'action' => sanitize_key($action),
                'severity' => sanitize_key($severity),
                'message' => sanitize_textarea_field($message),
                'context' => $context ? wp_json_encode($context) : null,
                'ip_address' => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
                'user_agent' => substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255),
                'created_at' => self::now(),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    public static function reference($prefix, $id)
    {
        return sprintf('%s-%s-%05d', $prefix, gmdate('Y'), $id);
    }

    public static function status_labels($type)
    {
        $statuses = [
            'company' => ['pending' => __('Pending', 'obydullah-b2b-portal'), 'approved' => __('Approved', 'obydullah-b2b-portal'), 'rejected' => __('Rejected', 'obydullah-b2b-portal'), 'suspended' => __('Suspended', 'obydullah-b2b-portal')],
            'rfq' => ['draft' => __('Draft', 'obydullah-b2b-portal'), 'submitted' => __('Submitted', 'obydullah-b2b-portal'), 'under_review' => __('Under review', 'obydullah-b2b-portal'), 'quoted' => __('Quoted', 'obydullah-b2b-portal'), 'ordered' => __('Ordered', 'obydullah-b2b-portal'), 'closed' => __('Closed', 'obydullah-b2b-portal'), 'rejected' => __('Rejected', 'obydullah-b2b-portal'), 'cancelled' => __('Cancelled', 'obydullah-b2b-portal')],
            'quote' => ['draft' => __('Draft', 'obydullah-b2b-portal'), 'sent' => __('Sent', 'obydullah-b2b-portal'), 'viewed' => __('Viewed', 'obydullah-b2b-portal'), 'accepted' => __('Accepted', 'obydullah-b2b-portal'), 'partially_accepted' => __('Partially accepted', 'obydullah-b2b-portal'), 'rejected' => __('Rejected', 'obydullah-b2b-portal'), 'expired' => __('Expired', 'obydullah-b2b-portal'), 'cancelled' => __('Cancelled', 'obydullah-b2b-portal'), 'superseded' => __('Superseded', 'obydullah-b2b-portal')],
            'purchase_order' => ['draft' => __('Draft', 'obydullah-b2b-portal'), 'issued' => __('Issued', 'obydullah-b2b-portal'), 'confirmed' => __('Confirmed', 'obydullah-b2b-portal'), 'acknowledged' => __('Acknowledged', 'obydullah-b2b-portal'), 'in_production' => __('In production', 'obydullah-b2b-portal'), 'dispatched' => __('Dispatched', 'obydullah-b2b-portal'), 'delivered' => __('Delivered', 'obydullah-b2b-portal'), 'completed' => __('Completed', 'obydullah-b2b-portal'), 'closed' => __('Closed', 'obydullah-b2b-portal'), 'cancelled' => __('Cancelled', 'obydullah-b2b-portal')],
        ];
        return $statuses[$type] ?? [];
    }
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
