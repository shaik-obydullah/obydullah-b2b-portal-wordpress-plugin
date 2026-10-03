# Obydullah B2B Portal

Customer-facing B2B RFQ, quotation, and purchase-order portal for WordPress. A supplier
manages companies, a catalog, and price lists in wp-admin; approved company contacts log
into a front-end portal, request quotes, and accept or reject quotations.

- **Version:** 1.0.0
- **Requires:** WordPress 6.5+, PHP 8.0+
- **Text domain:** `obydullah-b2b-portal`

Deliberately excludes payments, accounting, inventory, credit scoring, and tax calculation —
see [`docs/plan.md`](docs/plan.md) for the original scope.

## Installation

1. Copy the plugin folder to `wp-content/plugins/obydullah-b2b-portal`.
2. Activate **Obydullah B2B Portal** from the Plugins screen. Activation creates the
   `wp_obbp_*` tables, installs the roles, and schedules the daily `obbp_expire_quotes` cron.
3. Open **B2B Portal → Settings** and set the default currency, default country, and
   *Portal page* (a page containing the `[obbp_portal]` shortcode).
4. Add your first company and approve it. Approval provisions a portal login for its primary
   contact and emails them.

Schema installation happens once on activation. To pick up a schema change after an update,
deactivate and reactivate the plugin.

## Using the portal

Add `[obbp_portal]` to any page. Optional attributes:

| Shortcode | Attributes | Purpose |
| --- | --- | --- |
| `[obbp_portal]` | `default_view`, `title` | Full portal. `default_view` is the view shown before any `obbp_view` query arg. |
| `[obbp_catalog]` | — | Standalone catalog browser. |

Views are driven by `?obbp_view=`: `dashboard`, `catalog`, `rfqs`, `rfq`, `quotes`, `quote`,
`orders`, `order`. Anything else falls back to `default_view`, and then to `dashboard`. The
detail views (`rfq`, `quote`, `order`) take the record ID in `?obbp_id=`, and
`?obbp_print=1` on the `quote` view renders the printable version. Every detail view re-checks
company ownership before rendering, so guessing an `obbp_id` shows nothing.

## Roles and capabilities

| Role | Purpose |
| --- | --- |
| `administrator` | Receives every `obbp_*` capability on activation. |
| `obbp_manager` (B2B Manager) | All admin capabilities. |
| `obbp_sales` (B2B Sales) | Catalog, price lists, RFQs, quotes, purchase orders, activity — but not companies, settings, or CSV. |
| `obbp_customer` (B2B Customer) | `obbp_access_portal`, `obbp_submit_rfq`, `obbp_view_rfqs`, `obbp_view_quotes`, `obbp_accept_quotes`, `obbp_view_orders`, `obbp_reorder`. |

Portal capabilities are granted at company level, not role level: a user must hold
`obbp_access_portal` *and* be linked to a company whose status is `approved`, either as the
company owner or as an active contact. Use **Settings → Synchronize roles** after editing
roles manually.

## Admin screens

`B2B Portal` menu, each gated by its own capability: Dashboard, Companies, Catalog,
Price Lists, RFQs, Quotes, Purchase Orders, Activity Log, Import / Export, Settings.

## Data model

The catalog is a custom post type (`obbp_product`, non-public) with `_obbp_*` post meta, so it
inherits WordPress revisions and media handling. Everything transactional lives in custom
tables prefixed `{$wpdb->prefix}obbp_`:

`companies`, `company_contacts`, `price_lists`, `price_list_items`, `rfqs`, `rfq_items`,
`quotes`, `quote_items`, `purchase_orders`, `purchase_order_items`, `activity_logs`.

Company-owned rows use soft deletion (`deleted_at`); every read path filters it.

### Product meta

`_obbp_sku`, `_obbp_unit`, `_obbp_price`, `_obbp_currency`, `_obbp_availability`, `_obbp_min_order_qty`,
`_obbp_qty_step`, `_obbp_lead_time_days`, `_obbp_tax_rate`, `_obbp_is_service`, `_obbp_portal_visible`,
`_obbp_attributes`.

`_obbp_availability` is one of `in_stock`, `out_of_stock`, `backorder`, `made_to_order`,
`discontinued`. `discontinued` products are hidden from the portal and cannot be added to an RFQ.

## Workflow

Company approval, RFQ, quote, and purchase-order transitions are enforced as explicit state
machines — see [`docs/cases.md`](docs/cases.md) for the full tables and the failure cases each
rule prevents.

```
pending company -> approved company -> customer submits RFQ -> admin quotes
   -> customer accepts quote -> purchase order created -> order history
```

Pricing resolves per company in this order: the company's assigned price list, then the active
default price list. A list is only considered when it is active and its validity window covers
today. A price-list item overrides the product's own `_obbp_price`, `_obbp_min_order_qty`,
`_obbp_qty_step`, and `_obbp_lead_time_days`. If the list currency and the company currency
disagree, the list is skipped and the customer's own currency is used.

## CSV import and export

**B2B Portal → Import / Export**. Exports stream as CSV downloads; imports are validated
row by row and a bad row never aborts the batch.

| Entity | Import value | Export columns |
| --- | --- | --- |
| Companies | `companies` | name, email, phone, website, tax_id, address_line1, address_line2, city, state, postcode, country, status, status_note, price_list_id, currency, payment_terms |
| Catalog | `catalog` | id, sku, title, description, unit, price, currency, availability, min_qty, qty_step, lead_time_days, tax_rate, is_service, portal_visible, post_status |
| Price lists | `price_lists` | name, slug, description, currency, is_default, is_active, valid_from, valid_to |
| Price list items | `price_list` (alias `price_list_items`) | id, price_list_id, product_id, sku, unit_price, min_qty, qty_step, lead_time_days, is_active, note |
| RFQs / Quotes / POs / Activity | export only | see the Import / Export screen |

### Round-trip guarantee

Every export column set is a valid import for the same entity, so
**export → import is lossless for companies, catalog, price lists, and price list items.**

Import details worth knowing:

- `companies` requires `name` and `email`; the row matches an existing company by email.
- `catalog` requires `title` and `sku`; the SKU is the match key, so re-importing updates in
  place instead of creating duplicates. An empty `availability` falls back to `in_stock` and an
  invalid or missing `currency` falls back to the site default.
- `price_lists` requires `name` and `currency`; an empty `slug` is derived from the name.
  Marking a list `is_default` deactivates the default flag on every other list.
- `price_list` items require `sku` and `unit_price` and resolve their list from the row's
  `price_list_id`, falling back to the *Price list ID* field on the form. Rows upsert on
  `UNIQUE KEY list_product`, so `id` and `created_at` survive a re-import.
- Tolerates a trailing empty column and short rows, so files saved by Excel and Google Sheets
  import cleanly. Genuinely over-wide rows are reported per line.
- Blank `is_active` on a price item means active; `0` stays inactive.
- `min_qty` and `qty_step` are floored at 1 rather than stored as 0.

### Applying company status on import

`status` is a workflow state with side effects — approval provisions a portal login and every
transition sends an email. It is therefore **opt-in**: tick *Apply company status from the CSV*
to honour it.

When enabled, changes are routed through `OBBP_Workflow::set_company_status()` so the transition
table, the active-primary-contact requirement, provisioning, the audit log, and notifications all
still apply. Consequences:

- A company already in the target status is left alone, so re-importing an export is idempotent.
- Newly created companies are always created `pending` and reported as needing follow-up; a
  brand-new company has no primary contact, so `approved` is unreachable.
- A refused transition (for example, no active primary contact) is reported as a follow-up
  notice, not a skipped row, so the rest of the company data still lands.

## Security

- Every state-changing entry point goes through `admin_post_*` with a capability check against
  a per-action whitelist plus a nonce check. Unlisted actions are rejected.
- Company-scoped reads funnel through `OBBP_Workflow::company_rfq()`, `company_quote()`, and
  `company_po()`, each of which requires ownership and an `approved` company, so object IDs
  cannot be walked across tenants.
- All SQL uses `prepare()`. Interpolated fragments are table names from `$wpdb->prefix` only;
  search terms are bound via `esc_like()` as parameters.
- Output is escaped at the boundary. CSV exports neutralise leading `=`, `+`, `-`, and `@` on
  non-numeric cells to defuse spreadsheet formula injection.
- Uploads are limited to 5 MB and read with `is_uploaded_file()`.
- `uninstall.php` clears the cron, removes the plugin's roles and `obbp_*` capabilities, and
  drops the tables and catalog posts only when *Delete data on uninstall* was enabled.

## Scheduled work

`obbp_expire_quotes` runs daily and moves `sent`/`viewed` quotes past their `valid_until` to
`expired`. Notification emails are always sent **after** the transaction commits, so a mail
transport failure can never roll back a saved record.

## Development

Lint and test against the stack in `../docker-compose.yml` (`wordpress` on port 8010, `db` on
3308):

```sh
# syntax check
docker run --rm -i wordpress:cli php -l < includes/class-obbp-csv.php

# runtime smoke test (schema, roles, CSV round-trip, RFQ validation)
docker compose run --rm --no-deps --entrypoint wp wp-cli \
  eval-file /path/to/obbp-smoke.php --path=/var/www/html --allow-root
```

## File map

| Path | Role |
| --- | --- |
| `obydullah-b2b-portal.php` | Bootstrap, constants, activation/deactivation hooks. |
| `includes/class-obbp-activator.php` | Schema creation, `dbDelta` upgrades, cron. |
| `includes/class-obbp-roles.php` | Roles and capabilities. |
| `includes/class-obbp-repository.php` | Table names, CRUD helpers, pricing, access checks. |
| `includes/class-obbp-catalog.php` | `obbp_product` registration, product read helpers. |
| `includes/class-obbp-workflow.php` | All state machines and transactions. |
| `includes/class-obbp-mailer.php` | Notification emails. |
| `includes/class-obbp-csv.php` | CSV import/export. |
| `includes/class-obbp-admin.php` | Admin screens and the admin action dispatcher. |
| `includes/class-obbp-portal.php` | Front-end portal, shortcodes, quote CSV download. |
| `docs/plan.md` | Original scope and data model. |
| `docs/cases.md` | Case-by-case behaviour and edge cases. |
