# AGENTS.md

Guidance for AI agents working on the **Obydullah B2B Portal** WordPress plugin.

A supplier manages companies, a catalog, and price lists in wp-admin; approved company
contacts log into a front-end portal, request quotes, and accept or reject quotations.
Catalog lives in a custom post type; everything transactional lives in `{$wpdb->prefix}obbp_*`
tables. State changes run through explicit state machines in `OBBP_Workflow`.

Read `README.md` first for the feature surface and `docs/cases.md` for the behaviour and
failure case of every rule — including a documented known gap (superseding a sent quote).

## Layout

| Path | Role |
| --- | --- |
| `obydullah-b2b-portal.php` | Bootstrap: header, constants (`OBBP_*`), explicit `require_once` of every class, activation/deactivation hooks, `ensure_cron_scheduled` on `init`. |
| `includes/class-obbp-activator.php` | All `CREATE TABLE` statements, `dbDelta` install/upgrade, cron, default settings. |
| `includes/class-obbp-roles.php` | Role/capability definitions and `install()`. |
| `includes/class-obbp-repository.php` | `table()`, `now()`, `money()`, `quantity()`, `row/rows/insert/update/soft_delete`, pricing (`price_for_product`), access checks (`can_access_portal`, `company_owns_user`), `log()`, `reference()`, `status_labels()`. |
| `includes/class-obbp-catalog.php` | `obbp_product` CPT + taxonomies, meta box, `_obbp_*` meta save, `availability_options()`. |
| `includes/class-obbp-workflow.php` | Every state machine, transaction, and ownership check. Nothing else mutates business state. |
| `includes/class-obbp-mailer.php` | Notification emails; honours `email_enabled` on company and in settings. |
| `includes/class-obbp-csv.php` | Import/export endpoints and the per-entity row handlers. |
| `includes/class-obbp-admin.php` | Admin menu, screen rendering (inline PHP), the `admin_post_obbp_action` dispatcher. |
| `includes/class-obbp-portal.php` | Shortcodes, portal views, `admin_post_obbp_portal` dispatcher, quote print view and CSV download. |
| `uninstall.php` | Role/capability removal always; tables, products, options, and plugin user meta only when the setting is on. |
| `assets/` | `css/obbp-admin.css`, `css/obbp-portal.css`, `js/obbp-portal.js` (progressive enhancement only). |
| `docs/plan.md` | Original scope, including what v1 deliberately excludes. |
| `docs/cases.md` | Behaviour/failure-case tables per area. Update when a rule changes. |

## Environment and verification

No Composer, no npm, no test suite, no PHPCS config. Verification = PHP lint + wp-cli
runtime checks + a manual pass through the workflow.

The stack is `../../../../docker-compose.yml` (`wordpress` on :8010, `db` on :3308,
`wp-cli` service). `wp_data/` is bind-mounted, so edits here are live in the containers.
(README says `../docker-compose.yml`; that path is wrong — it is four levels up.)

```bash
# lint one file (php -l reads stdin)
docker run --rm -i wordpress:cli php -l < includes/class-obbp-workflow.php

# lint the whole plugin
docker run --rm -v "$PWD":/plugin -w /plugin wordpress:cli \
  sh -c 'for f in $(find . -name "*.php"); do php -l "$f" || exit 1; done'
```

Run the wp-cli checks from `../../../../` with the stack up (`docker compose up -d`):

```bash
docker compose run --rm --no-deps --entrypoint wp wp-cli plugin status obydullah-b2b-portal --path=/var/www/html --allow-root
docker compose run --rm --no-deps --entrypoint wp wp-cli db query "SHOW TABLES LIKE '%obbp_%'" --path=/var/www/html --allow-root
docker compose run --rm --no-deps --entrypoint wp wp-cli cron event run obbp_expire_quotes --path=/var/www/html --allow-root

# ad-hoc runtime probes
docker compose run --rm --no-deps --entrypoint wp wp-cli eval \
  'var_export(OBBP_Repository::status_labels("quote"));' --path=/var/www/html --allow-root
```

There is no schema version stamp. `install_schema()` runs on activation only, so after a
schema change deactivate and reactivate once for `dbDelta` to apply.

`bin/seed-demo-data.php` fills an empty database with products, price lists, companies in
every status, and RFQs/quotes/POs spread across the state machines, so the screens have rows
to review. It drives `OBBP_Workflow` rather than inserting rows, and reports a workflow
rejection as a skip instead of forcing it. `wp db query` fails on this stack (the MariaDB
client has no `caching_sha2_password` plugin) — use `wp eval` with `$wpdb` instead.

```bash
# reset then seed (OBBP_SEED_RESET truncates the tables, deletes obbp_product posts,
# the linked portal users and the catalog terms)
docker compose run --rm --no-deps -e OBBP_SEED_RESET=1 --entrypoint wp wp-cli eval-file \
  /var/www/html/wp-content/plugins/obydullah-b2b-portal/bin/seed-demo-data.php \
  --path=/var/www/html --allow-root
```

Only `wp-content/` is bind-mounted, so `wp eval-file` can read files inside the plugin
directory but not ones placed at the site root. `$argv` is null under `eval-file`, hence the
`OBBP_SEED_FORCE` / `OBBP_SEED_RESET` environment variables instead of CLI flags.

Minimum manual pass after touching the workflow: approve a company → customer adds items to
a draft RFQ → submit → admin creates and sends a quote → customer accepts (partially) → PO
exists and the RFQ reads `ordered`; then confirm the activity log rows.

## Conventions

Match the existing style. Do not add comments unless asked.

- **Prefixes.** Everything is `obbp_`/`OBBP_`: classes `OBBP_*`, methods and post actions
  `obbp_*`, tables `obbp_*`, option `obbp_settings`, capabilities
  `obbp_*`, roles `obbp_manager` / `obbp_sales` / `obbp_customer`, `WP_Error` codes
  `obbp_*`, CPT `obbp_product`, post meta `_obbp_*`, CSS classes `obbp-`.
- **The post type is `obbp_product`; its capabilities are still `obbp_*`.** The CPT declares
  `'capability_type' => ['obbp_product', 'obbp_products']` on purpose, so `OBBP_Roles` grants
  `edit_obbp_product`, `read_obbp_products`, etc. and existing role assignments keep working.
  Never rename the post type and the capability base in the same pass — that silently
  strips every catalog capability until someone re-runs **Settings → Synchronize roles**.
  Taxonomies are `obbp_product_cat` / `obbp_product_tag`. Admin menu slugs are `obbp-*`
  (`obbp-dashboard`, `obbp-catalog`, …, never `obbp-` — a legacy slug would
  `wp_die('Invalid plugin page.')`) and `is_plugin_screen()` matches on that `obbp-` prefix
  plus the explicit `obbp_product` post-type check for the CPT screens — keep both.
- **Two version literals, always bumped together.** `OBBP_VERSION` is a hard-coded literal
  and the `Version:` header is a second literal; a plugin header *cannot* reference a
  constant, because `get_plugin_data()` regex-matches the comment — `* Version: OBBP_VERSION`
  would render literally as "OBBP_VERSION" in the plugins screen and break update checks. So
  releasing means editing **both**: the header line and `define('OBBP_VERSION', …)`. This pair
  already drifted once (header `1.0.0` against constant `1.1.0`), which is why the rule is
  written down. Keep `readme.txt` `Stable tag:` and the top changelog heading in step as
  well — `docs/WordPress-Plugin-Checklist.md` requires the stable tag to match the current
  release. There is no schema version constant: `install_schema()` runs on activation only,
  so a schema change needs a deactivate and reactivate to reach existing installs.
  Regenerate the POT after a bump so `Project-Id-Version` follows.
- **PHP 8.0+, no namespaces.** Short array syntax everywhere (`[]`, never `array()`), no
  `declare(strict_types=1)`. Prefer 8.0 built-ins over string functions: `str_contains()`,
  `str_starts_with()`, `str_ends_with()` instead of `strpos()`, and `??`/`??=` over
  `isset()`. Use `match` for a value → value mapping (always with `default`), but keep
  `if`/`elseif` for genuine branching — a `match` is not a drop-in for a statement chain.
  Promote constructor properties when a class owns them (`OBBP_CSV::$notices`), and use
  named arguments when a call has to skip an earlier positional parameter
  (`create_rfq($id, $uid, source: 'portal', parent_rfq_id: $rfq)`). Nothing may use
  8.1+ syntax: no enums, `readonly`, `never`, first-class callables, `array_is_list()`,
  or `new` in initialisers.
- **One `final class` per file**, file named `class-obbp-<kebab>.php`, and the bootstrap must
  `require_once` it. Every class starts with `if (!defined('ABSPATH')) { exit; }`.
- **i18n.** The text domain is the plugin slug: **`Text Domain: obydullah-b2b-portal`** in the
  bootstrap header, matching `readme.txt` and the `Domain Path: /languages` header. The plugin
  deliberately does **not** call `load_plugin_textdomain()`: as a wp.org-hosted plugin its
  translations load just-in-time from the standard language folders (and since WP 6.7 a manual
  call before `init` is discouraged). Every user-facing string goes through `__()`, `esc_html__()`,
  `esc_html_e()`, `esc_attr_e()` etc. with the `'obydullah-b2b-portal'` literal as the domain
  argument — never the short `'obbp'` form, which is reserved for the `obbp_product` post
  type, `obbp-*` menu slugs, and `class-obbp-*` filenames. No bare English in markup. Keep the
  literal — `wp i18n make-pot` only extracts quoted domains, so a constant or variable would
  silently yield an empty POT. Regenerate the template with
  `wp i18n make-pot . languages/obydullah-b2b-portal.pot --domain=obydullah-b2b-portal --package-name="Obydullah B2B Portal"`.
- **Escaping.** Sanitise on the way in (`sanitize_text_field`, `sanitize_textarea_field`,
  `sanitize_email`, `esc_url_raw`, `absint`, plus `wp_unslash` on `$_POST`/`$_GET`), escape
  on the way out (`esc_html`, `esc_attr`, `esc_url`, `esc_textarea`, `esc_js`). Never print
  raw request or database values.
- **SQL.** Never interpolate a value into SQL. Prefer `$wpdb->insert()` / `$wpdb->update()` /
> `$wpdb->delete()`, which prepare the statement and take a format array
> (`['%s','%d']`); otherwise wrap the statement in `$wpdb->prepare()` with `%s`/`%d`
> placeholders. Building a `$sql` string first is fine **only** when every interpolated
  fragment is a `sanitize_sql_orderby()` result, an `absint()`, or a table name from
  `OBBP_Repository::table()` — and the `$params ? prepare($sql, $params) : $sql` shape used in
  `OBBP_Repository::rows()` / the admin list screens. Table names only ever come from
  `OBBP_Repository::table()` (`sanitize_key`'d); `ORDER BY` via `sanitize_sql_orderby()`;
  search terms bound as `'%' . $wpdb->esc_like($term) . '%'` parameters. Never interpolate a
  column name taken from user input.
- **`wp_unslash()` is mandatory, not optional.** Every superglobal read that feeds
  `sanitize_text_field()`, `sanitize_textarea_field()`, `sanitize_email()` or `esc_url_raw()`
  must be wrapped in `wp_unslash()` — none of those strip the backslashes `wp_magic_quotes()`
  adds, so a value like `O'Brien` is stored as `O\'Brien` without it. `isset()`/`empty()`
  presence probes, `$_FILES`, and reads already funnelled through `absint()` or
  `sanitize_key()` do not need it: `$_FILES` is never slashed, and those two strip
  backslashes themselves.
- **Meta save guards, in this order.** `OBBP_Catalog::save_product()` is the reference:
  `DOING_AUTOSAVE` → `wp_is_post_revision()` → nonce field present → `wp_verify_nonce()` →
  `current_user_can('edit_post', $post_id)`, then sanitise and `update_post_meta()`. Because
  it hangs off `save_post_obbp_product`, the `get_post_type()` check is implicit — do not add
  a redundant one. A new `save_post_*` handler copies this shape exactly.
- **Both dispatchers are gated.** `OBBP_Admin::handle_post()` checks the `$capabilities`
  whitelist then `wp_verify_nonce()`; `OBBP_Portal::handle_post()` additionally requires
  `is_user_logged_in()` **and** `OBBP_Repository::can_access_portal()` on both the action and
  the nonce. Admin GET screens use the private `cap()` helper, which `wp_die`s.
- **Data access.** Prefer `OBBP_Repository::row/rows/insert/update/soft_delete` over raw
  `$wpdb`. `row()`/`rows()` exclude `deleted_at IS NOT NULL` unless you pass
  `$include_deleted` — every read of a soft-deleted table must go through them or add the
  filter by hand.
- **Object cache.** Hot reads are cached with `wp_cache_*` under the `obbp` group via
  `OBBP_Repository::cache_get/cache_set/cache_delete`. Every writer MUST invalidate, or
  staleness leaks: `flush_company_access()` on company/contact/status writes,
  `flush_product_prices()` on product meta or price-item writes, `flush_price_list_items()`
  when items of a list change, `flush_price_list()` on list create/edit, `flush_settings()`
  on settings save. Membership caches (`cfu:`/`owns:`) are security data — never cache them
  without a matching flush via `flush_company_access()`. Dashboard counters and activity
  facets are TTL-only (`CACHE_TTL_COUNTS`, 60s) and need no invalidation. When adding a new
  mutation, check this section before writing — a missed flush is a security/price bug.
- **Numbers and dates.** `OBBP_Repository::money()` (2dp) and `quantity()` (4dp) — never
  `round()` inline. `OBBP_Repository::now()` for datetimes, `current_time('Y-m-d')` for dates.
- **Reference numbers.** Insert with a `TMP-<uuid>` placeholder, then update to
  `OBBP_Repository::reference('RFQ'|'QT'|'PO', $id)` in the same transaction, because the
  number embeds the auto-increment id.
- **Return contract.** Workflow/admin methods return `true`, an int id, a success message
  string, `array('message' => …, 'redirect' => …)`, or `WP_Error`. The two dispatchers
  (`OBBP_Admin::handle_post`, `OBBP_Portal::handle_post`) translate that into
  `obbp_message` / `obbp_error` query args and redirect.
- **Markup.** No template files — HTML is inline PHP inside the render methods. Admin
  screens use `.wrap.obbp-admin`, `obbp-panel`, `obbp-form`, `form-table`, `widefat striped`;
  portal views use the `obbp-*` classes already in `assets/css/obbp-portal.css`.

## Invariants that must not be broken

- **State changes only in `OBBP_Workflow`.** Every mutation is: validate →
  `START TRANSACTION` → re-read the row `… FOR UPDATE` and **re-check the transition
  against the locked status** → mutate → `COMMIT` → then `OBBP_Repository::log()` and
  `OBBP_Mailer::*()`. Logging and mail happen strictly after commit so a mail failure can
  never roll back saved data.
- **Roll back, never exit.** On any failure inside a transaction: `$wpdb->query('ROLLBACK')`
  then `return new WP_Error('obbp_…', …)`. Never `exit`/`wp_die` between `START TRANSACTION`
  and `COMMIT`. Approval links an existing WordPress user only — the plugin never creates
  accounts, so there is no user to delete on the failure path (see `set_company_status`).
- **Tenant isolation.** Any customer-facing read of an RFQ, quote, or PO goes through
  `OBBP_Workflow::company_rfq()` / `company_quote()` / `company_po()`, or
  `OBBP_Repository::company_owns_user()` plus an `approved` company. Never trust `obbp_id`.
- **Portal gating.** The portal requires login **and** `OBBP_Repository::can_access_portal()`
  (capability `obbp_access_portal` + a linked company in `approved` status). New portal POST
  actions need that check too.
- **Action whitelist.** `handle_post()` rejects any `obbp_do` not in its `$capabilities` map
  (capability whitelist) and then verifies the nonce. A new action must be added in three
  places: the `$capabilities` map, `run_action()`, and `redirect_for()`.
- **Audit everything.** Each mutation calls `OBBP_Repository::log($company_id, $object_type,
  $object_id, $action, $message, $context, $severity)`.
- **CSV export neutralises formulas.** `OBBP_CSV::neutralize_formula()` prefixes `= + - @`
  on non-numeric cells. Any new export must reuse it, and any new import entity must be a
  valid round-trip of its export columns.

## Checklist when adding things

- **New table or column** → add it to `install_schema()` in `class-obbp-activator.php` and
  add the table to the drop list in `uninstall.php`. `dbDelta` is idempotent, so edits to an
  existing `CREATE TABLE` are enough. There is no schema version to bump — a deploy with a
  schema change needs a deactivate and reactivate to reach existing installs.
- **New status** → add it to `OBBP_Repository::status_labels()`, to the transition map in
  `OBBP_Workflow`, and to the `.obbp-status-<status>` rules in both CSS files.
- **New capability** → add it to `OBBP_Roles::admin_capabilities()` /
  `customer_capabilities()` and to the relevant `handle_post()` whitelist. After editing
  roles by hand, admins re-sync via **Settings → Synchronize roles**.
- **New portal view** → add it to the `$allowed` list in `OBBP_Portal::shortcode()`, add the
  nav link, and route it in the view dispatch. Build links with `$this->url($view, $args)`.
- **New CSV entity** → wire both `handle_import()` (header rules, dispatch, per-row handler)
  and the `$queries` map in `handle_export()`.

## Out of scope

`docs/plan.md` excludes payments, accounting, inventory, credit scoring, complex tax
calculation, WooCommerce coupling, and mobile apps. Do not introduce them unless asked.

Do not add build tooling, dependencies, or a test framework without asking; there is none in
the project today. Do not commit unless asked.
