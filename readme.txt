=== Obydullah B2B Portal ===
Contributors: obydullah
Tags: b2b, catalog, quote, order, csv
Text Domain: obydullah-b2b-portal
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Customer-facing B2B portal: companies approve, customers request quotes, and your team quotes and issues purchase orders.

== Description ==

Obydullah B2B Portal turns your WordPress site into a self-service quoting channel for business customers. You manage companies, a product catalog, and per-customer price lists in wp-admin. An approved company contact signs in to the front-end portal, browses your catalog, builds a request for quotation, and accepts or rejects your quotation. Accepting creates the purchase order.

The whole flow is one chain, enforced as explicit state machines rather than loose status flags:

`pending company → approved company → customer submits RFQ → admin quotes → customer accepts → purchase order created → order history`

= What it does =

* **Companies and contacts** — company records with multiple contacts, approval and rejection with a reason, and portal access that is granted on approval.
* **Product catalog** — a private custom post type, so products inherit WordPress revisions, media handling, and search. Per-product SKU, unit, price, currency, availability, minimum order quantity, quantity step, and lead time.
* **Customer-specific price lists** — assign a price list to a company, or mark one list as the site default. Each list can be inactive or sit inside a validity window. A price list item overrides the product's own price, minimum quantity, quantity step, and lead time. If a list's currency and the company's currency disagree, the list is skipped and the customer's own currency is used.
* **RFQs** — customers add catalog items to a draft, attach quantities and per-item notes, then submit. Admins move them through draft, submitted, under review, quoted, ordered, closed, rejected, and cancelled.
* **Quotations** — price an RFQ line by line, apply a fixed or percentage discount, set a validity date, and send it. Customers view, print, download, or reject it. Quotes past their validity date expire on a daily cron.
* **Partial acceptance** — a customer can accept some lines and decline others by setting a quantity to zero. Only accepted lines become order lines, so declining an item never creates a phantom order.
* **Purchase orders** — created automatically from accepted quotes and tracked through draft, issued, confirmed, acknowledged, in production, dispatched, delivered, completed, and closed. The customer can acknowledge receipt from the portal.
* **Reorder** — copy the items of any past RFQ into a fresh draft.
* **Roles and capabilities** — three roles (B2B Manager, B2B Sales, B2B Customer) plus per-action capability checks. Portal access is granted per company, not per role.
* **CSV import and export** — import companies, catalog, price lists, and price list items. Export everything. Export to import is lossless for those four entities.
* **Activity log** — every state change is recorded with actor, object, action, message, and context.

= Design choices =

* **No external services.** The plugin loads no remote fonts, scripts, or styles, and makes no outbound HTTP requests of its own. Notification mail goes through your site's own `wp_mail` configuration, so it uses the mail transport you already chose. It sends no usage or telemetry data.
* **Transactional integrity.** Every state change validates, opens a transaction, re-reads the row locked `FOR UPDATE`, and re-checks the transition against the locked status. Concurrent acceptance of two quotations for one RFQ produces one purchase order, not two.
* **Tenant isolation.** Customer-facing reads of an RFQ, quotation, or purchase order are always scoped to a company the logged-in user belongs to, and require that company to be approved. Guessing a record ID in the URL returns nothing.
* **Mail never rolls back data.** Notifications are sent after the transaction commits, so a mail transport failure cannot undo a saved record.
* **Spreadsheet-safe exports.** Leading `=`, `+`, `-`, and `@` are neutralised in exported text cells so spreadsheets do not execute them as formulas. Numeric values are left intact so negative numbers still round-trip.

= What it deliberately does not do =

Payments, invoicing, accounting, inventory, credit scoring, tax calculation, WooCommerce coupling, and mobile apps are out of scope. This plugin covers request, quote, and order. If you need to take payment or raise an invoice, use a dedicated plugin alongside it.

== Screenshots ==

1. Companies in wp-admin, with approval status and portal contacts.
![Companies in wp-admin, with approval status and portal contacts.](assets/screenshot-1.png)

2. A company record, with its contacts, notes, and status history.
![A company record, with its contacts, notes, and status history.](assets/screenshot-2.png)

3. The catalog, with SKU, availability, and ordering rules per product.
![The catalog, with SKU, availability, and ordering rules per product.](assets/screenshot-3.png)

4. Price lists and per-customer pricing overrides.
![Price lists and per-customer pricing overrides.](assets/screenshot-4.png)

5. An RFQ in wp-admin, with the customer's requested lines and quantities.
![An RFQ in wp-admin, with the customer's requested lines and quantities.](assets/screenshot-5.png)

6. The quote builder, with discounts, shipping, tax, and validity date.
![The quote builder, with discounts, shipping, tax, and validity date.](assets/screenshot-6.png)

7. The customer dashboard in the portal, showing recent RFQs, quotes, and orders.
![The customer dashboard in the portal, showing recent RFQs, quotes, and orders.](assets/screenshot-7.png)

8. The customer-facing catalog, priced from the company's own price list.
![The customer-facing catalog, priced from the company's own price list.](assets/screenshot-8.png)

9. Building and submitting a new RFQ.
![Building and submitting a new RFQ.](assets/screenshot-9.png)

10. A quotation in the portal, with the printable view and accept or reject controls.
![A quotation in the portal, with the printable view and accept or reject controls.](assets/screenshot-10.png)

11. An issued purchase order.
![An issued purchase order.](assets/screenshot-11.png)

== Installation ==

1. Go to **Plugins → Add New**, search for "Obydullah B2B Portal", or upload the plugin zip.
2. Click **Install Now**, then **Activate**.
3. Activation creates the `wp_obbp_*` database tables, installs the plugin roles, and schedules the daily `obbp_expire_quotes` cron event.
4. Open **B2B Portal → Settings** and set the default currency, default country, and the portal page — any page containing the `[obbp_portal]` shortcode.
5. Add your first company and its primary contact, then approve the company. Approval provisions the portal login for that contact and emails them.
6. Add products under **B2B Portal → Catalog**, then set up price lists under **B2B Portal → Price Lists**.

= Shortcodes =

`[obbp_portal]` renders the full portal. It accepts a `default_view` attribute for the view shown before any navigation, and a `title` attribute for the portal heading.

`[obbp_catalog]` renders a standalone catalog browser for sites that only want the catalog page.

= Using the portal =

Add `[obbp_portal]` to a page. Views are driven by the `obbp_view` query argument: `dashboard`, `catalog`, `rfqs`, `rfq`, `quotes`, `quote`, `orders`, and `order`. The detail views take a record ID in `obbp_id`, and adding `obbp_print=1` to the `quote` view renders a printable version.

Every detail view re-checks company ownership before rendering, so requesting a record ID belonging to another company shows nothing.

== Frequently Asked Questions ==

= Where is my data stored? =

In your own WordPress database, on your own hosting. The catalog uses a private custom post type; companies, contacts, price lists, RFQs, quotations, purchase orders, and the activity log use custom tables prefixed with your WordPress table prefix.

= Do I need WooCommerce? =

No. There is no WooCommerce dependency and no coupling of any kind. The catalog is managed entirely inside this plugin.

= Can different companies see different prices? =

Yes, and that is the point. Pricing resolves per company: first the company's assigned price list, then the active default list. A list is only considered when it is active and its validity window covers today. If the list currency and the company currency disagree, the list is skipped and the product's own price is used in the company's currency.

= What happens when a customer only wants some of the quoted lines? =

They set the quantity to zero on the lines they do not want and accept. The quotation is recorded as partially accepted, and the resulting purchase order contains only the accepted lines. A quantity must be answered for every line — a missing quantity is rejected rather than silently treated as zero.

= Can I change a quotation after sending it? =

Not directly. Once a quotation is sent, the RFQ is blocked from being re-quoted until the customer accepts or rejects it, or the daily cron expires it. To change pricing after sending, wait for expiry or have the quotation rejected, then create a fresh one. A supersede transition is a known gap, noted in the plugin documentation.

= Can two customers accept the same quotation at the same time? =

Only one purchase order is created. The RFQ row is locked for the duration of acceptance and the transition is re-checked against the locked status, so a double-clicked or duplicated Accept cannot produce two orders. The second request is told the RFQ has already been ordered.

= How do I stop customers seeing each other's data? =

They cannot. Every customer-facing read of an RFQ, quotation, or purchase order is scoped to a company the signed-in user belongs to, and that company must be in the approved state. Portal access additionally requires the `obbp_access_portal` capability. Record IDs are never trusted on their own.

= Does the plugin send anything to an external service? =

No. It loads no remote fonts, scripts, or styles, makes no outbound HTTP requests, and collects no usage or telemetry data. Email is sent through your site's own WordPress mail configuration.

= Will deleting the plugin delete my data? =

Not by default. Roles and capabilities are always removed. The custom tables, catalog products, settings, and any WordPress users the plugin created are only deleted if you enabled "Delete data on uninstall" in the settings first.

= Is CSV export safe to open in Excel or Google Sheets? =

Yes for the common case. Leading `=` `+` `-` and `@` characters in exported text cells are prefixed so spreadsheets do not execute them as formulas, while numeric values are left untouched. Imports also tolerate a UTF-8 BOM, trailing empty columns, and short rows, so files saved by Excel and Google Sheets import cleanly.

= Can I import my existing data? =

Yes. Companies, catalog, price lists, and price list items can all be imported from CSV. Companies match on email and the catalog matches on SKU, so re-importing an export updates records in place rather than creating duplicates. A single bad row is reported and skipped rather than discarding the whole file. RFQs, quotations, purchase orders, and the activity log can be exported but not imported.

= Does it work with my theme or page builder? =

Yes. The portal uses its own stylesheet and script, and the admin screens use their own stylesheet. The portal page is normal front-end content that the shortcode output sits inside, so page builders can lay it out as they would any other block of content.

== Changelog ==

= 1.0.0 =

* Company management with approval workflow, contacts, and automatic portal login provisioning.
* Private product catalog with SKU, unit, price, currency, availability, minimum quantity, quantity step, and lead time.
* Per-company price lists with validity windows, a default list, and currency-conflict handling.
* RFQ lifecycle from draft through submission, with per-item quantities and notes.
* Quotation creation with line pricing and fixed or percentage discounts, validity dates, printable and CSV download.
* Partial quote acceptance generating a purchase order from accepted lines only.
* Purchase order tracking through fulfilment, with customer acknowledgement and reorder.
* Transactional state machines with row locking and guarded transitions.
* Company-scoped access control on every customer-facing read.
* CSV import and export with lossless round-trip for companies, catalog, price lists, and price list items.
* Spreadsheet formula neutralisation on export.
* Activity log recording every state change.
* Daily quote expiry cron.
* Three roles with per-action capability checks and company-level portal access.
* No external service dependencies, remote assets, or outbound HTTP requests.
