# WordPress.org Plugin Directory — General Upload Checklist

Reusable checklist for validating any plugin before submitting to WordPress.org.

Plugin: **`{Plugin Name}`**
Slug / Text Domain: `{plugin-slug}`
Stable Tag: `{X.Y.Z}`

---

## 1. Plugin Header (main plugin file)

- [ ] Plugin Name matches readme.txt title
- [ ] Text Domain is a valid slug matching the plugin folder/slug (lowercase, dashes, no spaces)
- [ ] Version: `{X.Y.Z}` (must match Stable tag)
- [ ] Requires at least: valid WP version
- [ ] Requires PHP: `8.0` or higher
- [ ] Domain Path: `/languages`
- [ ] License: `GPL-2.0-or-later` + License URI (must be GPL-compatible)
- [ ] Author + Author URI filled in
- [ ] Plugin URI points to a real page (no `example.com`, no placeholders)
- [ ] Description identical to the short description in readme.txt
- [ ] `Requires Plugins:` header added if the plugin depends on another plugin
- [ ] WooCommerce-dependent plugins: add `WooCommerce requires at least:` and `WooCommerce tested up to:` headers (mirrored in readme.txt)

## 2. readme.txt

- [ ] Follows the standard format (`=== Title ===`, Contributors, Tags, Stable tag…)
- [ ] Short description (above `== Description ==`) matches plugin header description exactly
- [ ] Stable tag matches the current release and a matching changelog entry exists
- [ ] Tested up to value is the **current** WP major version (verify before every submit)
- [ ] All sections present: Description, Installation, FAQ, Screenshots, Changelog
- [ ] Validate: https://wordpress.org/plugins/developers/readme-validator/

## 3. Naming & Collisions

- [ ] Unique prefix on all functions, classes, constants, options, hooks, transients (e.g. `{prefix}_`, `{Prefix}_`)
- [ ] No generic function names that could collide with other plugins or core
- [ ] Folder name = plugin slug = text domain (the zip folder name becomes the slug)

### Prefix Convention — Obydullah Personal Accounting

- [ ] Code prefix is **`OPA_`** (constants/macros) and **`opa_`** (functions, classes, hooks, AJAX actions, DB tables, nonces, text-domain-independent strings) — **never** `OBY_`/`oby_` or `LPA_`/`lpa_`
- [ ] Constants: `OPA_VERSION`, `OPA_PLUGIN_DIR`, `OPA_PLUGIN_URL`, `OPA_TABLE_PREFIX` (`'opa_'`)
- [ ] Functions: `opa_*` (e.g. `opa_create_tables()`, `opa_ajax_router()`, `opa_log_activity()`, `opa_page_dashboard()`)
- [ ] AJAX: single endpoint `wp_ajax_opa_action`, nonce `opa_nonce`, localized JS object `opaAjax`
- [ ] DB tables: `opa_wallets`, `opa_incomes`, `opa_expenses`, `opa_cashbook`, `opa_activities`, `opa_configurations`
- [ ] File names use the **`opa-`** prefix (hyphen): `opa-style.css`, `opa-script.js`, `includes/opa-database.php`, `includes/opa-admin-menu.php`, `includes/opa-ajax-handlers.php`, `includes/opa-pages.php`, `includes/views/opa-*.php`
- [ ] The main bootstrap file `obydullah-personal-accounting.php` and `languages/obydullah-personal-accounting.pot` keep the plugin-slug name (matches Text Domain) — do NOT prefix with `opa-`
- [ ] Quick check — the following must output nothing:
      `grep -rniE '\bOBY|\boby|LPA_|lpa_' . --include="*.php" --include="*.js" --include="*.css"`
- [ ] After renaming any file, update every `require`/`include` reference (bootstrap `require_once`s, `includes/opa-pages.php` view includes, docs)
- [ ] Regenerate the POT after any file rename (stale `#:` source paths break translation reference tracking):
      `wp i18n make-pot . languages/obydullah-personal-accounting.pot --slug=obydullah-personal-accounting`

## 4. Internationalization

- [ ] One single text domain used consistently in every `__()`, `_e()`, `esc_html__()`, `esc_attr_e()`, `_x()`, `_n()` call:
      `grep -rn "'{old-domain}'" . --include="*.php"` returns nothing
- [ ] `load_plugin_textdomain()` is **NOT required** for wp.org-hosted plugins — since WP 4.6 translations are loaded just-in-time from the standard `languages/`/`wp-content/languages` folders, and since WP 6.7 calling it manually is *discouraged*. Rely on the `Text Domain:` + `Domain Path: /languages` header lines instead.
      If you still call it (non-wp.org or custom path), hook it to `init` — `plugins_loaded` (or earlier) triggers a WP 6.7+ notice:
      `_load_textdomain_just_in_time was called incorrectly … Translations should be loaded at the init action or later.`
      Note: the function itself is not deprecated; only its 2nd parameter (`$deprecated`) is — pass `false` and use `$plugin_rel_path`.
- [ ] Generate POT file:
      `wp i18n make-pot . languages/{plugin-slug}.pot --slug={plugin-slug}`

## 5. Version Consistency

- [ ] Plugin header `Version:` = `{X.Y.Z}`
- [ ] Version constant in code = `'{X.Y.Z}'`
- [ ] readme.txt `Stable tag:` = `{X.Y.Z}`
- [ ] Changelog entry for `{X.Y.Z}` exists

## 6. Code Quality & Security

- [ ] `defined( 'ABSPATH' ) || exit;` at the top of every PHP file
- [ ] Nonces on ALL forms/AJAX/REST writes (`wp_verify_nonce`, `check_ajax_referer`)
- [ ] Sanitize + unslash superglobals **ASAP** at their very first touch — never read raw:
      ```php
      // POST / form flow
      if ( ! isset( $\_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $\_POST['nonce'] ) ), 'action_name' ) ) {
      wp_die( esc_html\_\_( 'Security check failed.', '{plugin-slug}' ) );
      }

      // GET / AJAX flow
      if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) ), 'action_name' ) ) {
          wp_send_json_error( __( 'Security verification failed', '{plugin-slug}' ) );
      }

      // AJAX handler — verify nonce (sanitize the input first):
      $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
      if ( ! wp_verify_nonce( $nonce, 'orpl_edit_customer' ) ) {
          wp_send_json_error( __( 'Security verification failed', '{plugin-slug}' ) );
      }
      ```

- [ ] Every other superglobal access (`$_GET`, `$_POST`, `$_REQUEST`, `$_SERVER`, `$_COOKIE`) wrapped in `sanitize_*()` + `wp_unslash()` (or a cast like `(int)` / `absint()`) on first touch
- [ ] Form field reading pattern — read each field with its proper sanitizer + fallback default:
      `php
    $in_amount    = floatval( $_POST['in_amount'] ?? 0 );
    $description  = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
    $entry_date   = sanitize_text_field( wp_unslash( $_POST['entry_date'] ?? '' ) );
    `
      (`sanitize_text_field` strings, `sanitize_textarea_field` long text, `sanitize_email` emails, `sanitize_key` enums/slugs, `floatval`/`(int)` numbers)
- [ ] Database writes use `$wpdb->insert()` / `update()` / `delete()` — **always** with explicit format array
      (`%s` string, `%d` int, `%f` float)

      **INSERT query** — sanitize each value before inserting:
      ```php
      // Sanitize input before insert:
      // wp_unslash()          → WordPress adds slashes to incoming request data; this removes them.
      // sanitize_text_field() → cleans the value as plain text, removing unwanted markup / invalid chars.
      $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

      $result = $wpdb->insert(
          $this->customers_table,
          [
              'name'       => $name,
              'email'      => $email,
              'mobile'     => $mobile,
              'address'    => $address,
              'status'     => $status,
              'created_at' => current_time( 'mysql' ),
          ],
          [ '%s', '%s', '%s', '%s', '%s', '%s' ]
      );
      ```

      **UPDATE query** — `$wpdb->update()` handles the SQL preparation for you:
      ```php
      $result = $wpdb->update(
          $this->customers_table,
          [
              'name'    => $name,
              'email'   => $email,
              'mobile'  => $mobile,
              'address' => $address,
              'status'  => $status,
          ],
          [ 'id' => $id ],
          [ '%s', '%s', '%s', '%s', '%s' ],
          [ '%d' ]
      );
      ```

      **DELETE query**:
      ```php
      $result = $wpdb->delete(
          $this->customers_table,
          [ 'id' => $id ],
          [ '%d' ]
      );
      ```
- [ ] Capability checks (`current_user_can()`) before any data mutation
- [ ] Sanitize ALL input (`sanitize_text_field`, `sanitize_key`, `absint`, …)
- [ ] Escape ALL output (`esc_html`, `esc_url`, `esc_attr`, `wp_kses_post`)
- [ ] Displaying text = translate + escape TOGETHER. Every string shown to the user must use a translation function with the plugin text domain, already escaped for its output context:
      ```php
      <?php esc_html_e( 'Description', 'obydullah-restaurant-pos-lite' ); ?>
      ```
      - `__()`  → returns translated string (use when assigning to a variable)
      - `_e()`  → echoes translated string — **never use raw; always prefer the esc_ variants**
      - `esc_html__()` / `esc_html_e()` → translated + safe inside HTML content (default choice)
      - `esc_attr__()` / `esc_attr_e()` → translated + safe inside HTML attributes (`title=""`, `placeholder=""`, `aria-label=""`)
      - `esc_html_x()` / `_x()` → context disambiguation ("Back" verb vs noun)
      - `_n()` singular/plural
      Rules:
      - One single text domain everywhere — the plugin slug, e.g. `'lime-micro-erp'`
      - Never concatenate translatable strings: `sprintf( __( 'Hello %s', 'slug' ), $name )` instead of `__( 'Hello ' ) . $name`
      - Dynamic values go OUTSIDE the translation function or through `sprintf` with `%s`/`%d` placeholders
      - **Every `__()` / `_e()` / `_n()` / `_x()` string containing placeholders (`%s`, `%d`) MUST have a `translators:` comment on the line directly above** (PHPCS `WordPress.WP.I18n.MissingTranslatorsComment`):
        ```php
        /* translators: %s: leave request status (approved or rejected). */
        micro_erp_redirect_notice( sprintf( __( 'Leave request %s.', 'lime-micro-erp' ), $status ) );

        // Multiple placeholders → numbered + described:
        /* translators: 1: percentage, 2: total number of employees. */
        sprintf( __( '%1$d%% of %2$d employees', 'lime-micro-erp' ), $pct, $total_emp );
        ```
- [ ] `$wpdb->prepare()` for every dynamic query; no raw user input in SQL
- [ ] SELECT query pattern — resolve the table name once, then always `prepare()` before executing:
      ```php
      // Table name: WP prefix + plugin slug prefix, resolved ONCE (constructor or property).
      $this->customers_table = $wpdb->prefix . 'orpl_customers';

      // SELECT Query:
      $customers = $wpdb->get_results(
          $wpdb->prepare(
              "SELECT id, name, email, mobile, address, status, created_at
               FROM {$this->customers_table}
               WHERE id = %d",
              $customer_id
          )
      );
      ```
      Process:
      ```
      $wpdb->prepare()
             ↓
      Prepare a safe SQL query
             ↓
      $wpdb->get_results()
             ↓
      Execute query and return results
      ```
      Rules:
      - `%d` int, `%s` string, `%f` float — one placeholder per argument, never interpolate variables directly into SQL
      - Only trusted identifiers (table/column names built from constants) may go into `{$var}` interpolation; anything from user input must be a `%s`/`%d` placeholder
      - **Table name style inside SQL strings**: interpolate `{$wpdb->prefix}micro_erp_x` directly — never concatenate `. micro_erp_table( 'x' ) .` into the string:
        ```php
        // GOOD:
        $used = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}micro_erp_journal_lines WHERE account_id = %d",
                $id
            )
        );

        // BAD (concatenation inside the SQL string):
        $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . micro_erp_table( 'journal_lines' ) . " WHERE account_id = %d", $id ) );
        ```
        `micro_erp_table( 'x' )` is still used as the first argument of `$wpdb->insert()` / `update()` / `delete()` (no SQL string involved there).
      - **Prescribed query style** — every `prepare()` call shows its values as explicit individual arguments. No `$args` arrays, no `$where` fragments interpolated into SQL. Optional filters are handled with one branch per filter combination:
        ```php
        // COUNT:
        if ( $type_filter && $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $total_items = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}micro_erp_contacts WHERE type = %s AND (name LIKE %s OR email LIKE %s OR company LIKE %s)",
                    $type_filter,
                    $like,
                    $like,
                    $like
                )
            );
        } elseif ( $type_filter ) {
            ...
        } elseif ( $search ) {
            ...
        } else {
            // No filters — still use prepare() with a seeded placeholder:
            $total_items = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}micro_erp_contacts WHERE 1 = %d",
                    1
                )
            );
        }

        // LIST (same branches, plus pagination args):
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}micro_erp_contacts ORDER BY name ASC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );
        ```
        Rules:
        - One placeholder = one visible argument, in order — count them before running
        - `LIKE` values: wrap once with `'%' . $wpdb->esc_like( $search ) . '%'`, pass as `%s`
        - SQL-native `%` patterns (`DATE_FORMAT` masks etc.): never interpolate or `%%`-escape them in the SQL string — pass the mask as a `%s` argument instead:
          ```php
          $rows = $wpdb->get_results(
              $wpdb->prepare(
                  "SELECT DATE_FORMAT(sale_date, %s) AS ym FROM {$wpdb->prefix}micro_erp_sales GROUP BY ym LIMIT %d",
                  '%Y-%m',
                  12
              )
          );
          ```
        - **Never interpolate column/table names from variables** (PCP: `Unescaped parameter ... assigned unsafely` — it can't trace through ternaries/includes). Pick in PHP instead:
          ```php
          // BAD:  $col = $is_x ? 'l.debit' : 'l.credit';  "SELECT SUM({$col}) ..."
          // GOOD: "SELECT SUM(l.debit) AS debit_total, SUM(l.credit) AS credit_total ..."
          //       then: $total = $is_x ? (float) $row->debit_total : (float) $row->credit_total;
          ```
        - Exception: dynamic-length `IN (...)` lists must build placeholders with `array_fill()` and pass the ID array (see below)
      - **Dynamic `IN (...)` clauses**: build placeholders with `array_fill()`, never interpolate IDs:
        ```php
        $in_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $lines = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}micro_erp_journal_lines WHERE entry_id IN ({$in_placeholders})",
                $ids
            )
        );
        ```

- [ ] Validate: untrusted file uploads, MIME types, path traversal
- [ ] Run Plugin Check (PCP): install **Plugin Check** → Tools → Plugin Check
- [ ] PHP lint all files:
      `find . -name "*.php" -not -path "./.git/*" -exec php -l {} \;`
- [ ] PHPCompatibility scan for the declared minimum PHP version
- [ ] No debugging leftovers (`var_dump`, `print_r`, `error_log`, `console.log`, `error_reporting`)
- [ ] No calls to external code at load time that could fatal (guard third-party plugins with `function_exists` / `class_exists`)

## 7. Assets (directory page)

Create an SVN `assets/` folder (NOT inside the plugin folder):

- [ ] Banner 772×250 PNG/JPG — `assets/banner-772x250.png`
- [ ] Banner retina 1544×500 — `assets/banner-1544x500.png`
- [ ] Icon 128×128 + 256×256 — `assets/icon-128x128.png`, `assets/icon-256x256.png`
- [ ] Screenshots ≥1200×900 px named `screenshot-1.png`, `screenshot-2.png`, … matching readme.txt entries
- [ ] Screenshot captions in readme.txt match image order

## 8. Privacy & Guidelines

- [ ] No external HTTP calls, telemetry, or tracking (disclose anything that exists in the Description)
- [ ] No phone-home license checks on wp.org-hosted code
- [ ] No obfuscated/minified-only code; everything human-readable
- [ ] No "coming soon" placeholders; plugin must be functional
- [ ] No upsells/advertising served from remote servers inside wp.org-hosted code
- [ ] All third-party libraries GPL-compatible and credited
- [ ] Trademark-safe name — check https://wordpress.org/plugins/ for conflicts and existing trademarks
- [ ] GDPR: any stored personal data documented; data export/erase supported if applicable

## 9. Packaging & Submission

The distributed folder name becomes your slug — it must be `{plugin-slug}`.

```bash
# from the parent directory of the plugin folder
mkdir -p /tmp/opencode/{plugin-slug}
rsync -a --exclude='.git*' \
      --exclude='docs' \
      --exclude='WordPress-Plugin-Checklist.md' \
      --exclude='.wordpress-org' \
      --exclude='node_modules' \
      ./<plugin-folder>/ /tmp/opencode/{plugin-slug}/
cd /tmp/opencode && zip -r {plugin-slug}.zip {plugin-slug}
```

- [ ] Zip root contains folder `{plugin-slug}/` with the main plugin file directly inside it
- [ ] Excluded from zip: `.git*`, `docs/`, dev checklists, node_modules, build scripts
- [ ] Submit: https://wordpress.org/plugins/developers/add/
- [ ] After approval — SVN:

```
svn co https://plugins.svn.wordpress.org/{plugin-slug}
# trunk/       ← plugin files (no assets)
# assets/      ← banners, icons, screenshots
# tags/{X.Y.Z}/ ← copy of trunk for each release
svn ci -m "Release {X.Y.Z}"
```

---

## Quick Validation Commands

```bash
# old text domain remnants (should output nothing)
grep -rn "old-text-domain" . --include="*.php"

# version strings across the plugin
grep -rn "{X.Y.Z}" *.php readme.txt

# direct file access guards present everywhere
grep -rL "ABSPATH" . --include="*.php"

# debugging leftovers
grep -rn "var_dump\|print_r\|error_log(" . --include="*.php"

# superglobals NOT immediately sanitized/unslashed (review each hit)
grep -rn '\$_GET\|\$_POST\|\$_REQUEST\|\$_SERVER\|\$_COOKIE' . --include="*.php" | grep -v "wp_unslash\|sanitize\|isset\|empty(\|(int)\|absint"
```

## Official References

- Plugin guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- readme validator: https://wordpress.org/plugins/developers/readme-validator/
- Header requirements: https://wordpress.org/plugins/developers/#plugin-header-fields
