# Cases

Case-by-case behaviour of `obydullah-b2b-portal`: what each rule does and which failure it
prevents. Grouped by area. Companion to [`../README.md`](../README.md).

---

## 1. Company approval

Transition table enforced by `OBBP_Workflow::set_company_status()`. The row is re-read
`FOR UPDATE` inside the transaction and the transition is re-checked against the locked
status, so two admins clicking at once cannot both win.

| From | Allowed to |
| --- | --- |
| `pending` | `approved`, `rejected` |
| `approved` | `suspended` |
| `rejected` | `approved` |
| `suspended` | `approved`, `rejected` |

| Case | Behaviour | Prevents |
| --- | --- | --- |
| Approve with no active primary contact | `obbp_primary_contact_required`, rolled back | A company approved with nobody able to receive the portal login |
| `rejected` / `suspended` with a blank reason | `obbp_note_required` | Silent suspensions nobody can explain later |
| Contact email already has a WordPress user | The existing user is **reused** and granted `obbp_customer`, not duplicated | The same human ending up with two logins |
| That user is already linked to a different company | `obbp_user_already_linked`, rolled back | One portal login serving two competing companies |
| Provisioning fails part-way | A user created during the attempt is deleted again in the same transaction | Orphaned WordPress users on every retry |
| Two concurrent approvals | Second one re-checks against the locked row and returns `obbp_invalid_status` ("no longer allowed") | Double-provisioned users, two "approved" audit entries |
| Commit fails after provisioning | Provisioned user deleted, error returned | Approved company whose owner cannot log in |
| Portal access after suspension | `company_for_user()` requires `status = 'approved'` | A suspended company retaining portal access |

---

## 2. Catalog and pricing

| Case | Behaviour | Prevents |
| --- | --- | --- |
| Adding a `discontinued` or `portal_visible = 0` product to an RFQ | `obbp_product_unavailable` | Ordering something withdrawn from sale |
| Quantity below `min_qty` | `The minimum quantity is N` | Orders the supplier cannot fill |
| Quantity off the `qty_step` increment | `Use increments of N` | Quantities the price list does not cover |
| `NaN` / `INF` quantity | `obbp_invalid_quantity` | Float corruption propagating into totals |
| Company currency ≠ product currency | `obbp_currency_mismatch` | Quotes issued in a currency the customer cannot pay |
| Price list window does not cover today | List skipped, product defaults used | Honouring a price list outside its validity period |
| Price-list item exists for the product | Its `unit_price`, `min_qty`, `qty_step`, `lead_time_days` win over product meta | Quoting list customers at list prices |
| Several valid lists | Company list first, then the active default list | Ambiguous pricing |

`price_for_product()` is null-safe on the company argument, so a company that disappears
mid-request cannot produce a fatal.

---

## 3. RFQ lifecycle

Transitions enforced by `OBBP_Workflow::transition_rfq()`:

| From | Allowed to |
| --- | --- |
| `submitted` | `under_review`, `rejected`, `cancelled`, `closed` |
| `under_review` | `rejected`, `cancelled`, `closed` |
| `quoted` | `rejected`, `cancelled`, `closed` |
| `ordered` | `closed` |

| Case | Behaviour | Prevents |
| --- | --- | --- |
| Adding an item when no draft exists | A draft RFQ is created for the company, then the item is added | Losing the first catalog click |
| Adding the same product twice | Quantities are summed and **re-validated** against the combined total | Two lines that jointly violate `min_qty` |
| Two users adding items at once | The company's newest draft is locked `FOR UPDATE`; the second writer finds it | Two parallel drafts for one company |
| Submitting an empty RFQ | `obbp_empty_rfq` | Empty requests reaching sales |
| **Submitting a stale draft** | Every item is re-validated against *current* `min_qty`/`qty_step`; failure returns `obbp_invalid_quantity` and the RFQ stays `draft` | A reorder of last year's quantities being submitted after the rules changed |
| Reordering an RFQ | Unavailable products are filtered out; if none remain, `obbp_reorder_unavailable` | Reordering discontinued items |
| Rejecting / cancelling without a reason | `obbp_reason_required` | Untraceable status changes |
| Editing an item on a submitted RFQ | `obbp_item_unavailable` | Customers editing a request already being quoted |

---

## 4. Quote lifecycle and acceptance

| Case | Behaviour | Prevents |
| --- | --- | --- |
| Quoting an RFQ that is `ordered` or `closed` | `obbp_rfq_not_quotable` (`submitted`, `under_review`, `quoted` only) | A second purchase order for one request |
| Sending a quote whose RFQ moved on | `obbp_rfq_not_open` after the RFQ is re-read `FOR UPDATE` | Sending a quote against a cancelled RFQ |
| Saving or sending a non-draft quote | `obbp_quote_immutable` / `obbp_quote_not_draft` | Mutating a quote the customer already accepted |
| Sending a quote whose `valid_until` has passed | `obbp_quote_expired` | Sending a lapsed quote |
| Issuing a revised quote | Create a **new** quote from the RFQ — only possible once the previous one leaves the open set. See the gap note below. | — |
| RFQ already has a `draft` quote | The existing draft's ID is returned; no duplicate draft | Two drafts racing for one RFQ |
| RFQ already has a `sent` / `viewed` / accepted quote | `obbp_quote_exists` | Two live quotes the customer could both accept |
| Opening a sent quote as a customer | Status becomes `viewed`, logged once | Repeat emails on every page reload |
| **Accepting with a quantity above the quoted quantity** | `obbp_invalid_quantity` | A customer inflating quantities to inflate the order |
| Accepting with a non-numeric or `INF` quantity | `obbp_invalid_quantity` | Totals computed from garbage |
| Accepting some items at 0 | Allowed; quote becomes `partially_accepted` | Forcing an all-or-nothing decision |
| Accepting every item at 0 | `obbp_no_items_accepted` | An "acceptance" that creates an empty order |
| Missing a quantity for any item | `obbp_invalid_quantity` — every item must be answered | Silent partial acceptance |
| Accepting an expired quote | `obbp_quote_expired` | Ordering against lapsed prices |
| Concurrent acceptance of two quotes for one RFQ | The RFQ row is locked `FOR UPDATE`; the second sees a purchase order and returns `obbp_rfq_already_ordered` | Double ordering from a double-clicked Accept |
| PO creation fails mid-acceptance | Whole transaction rolls back, including `accepted_quantity` | A quote marked accepted with no purchase order |
| Downloading a quote CSV | Nonce, login, `can_access_portal()`, and `company_quote()` ownership all required | Reading another company's pricing by guessing an ID |
| `obbp_expire_quotes` cron | Selects `sent`/`viewed` quotes past `valid_until`, then applies a guarded `UPDATE ... WHERE status IN ('sent','viewed')` per quote and logs each | Re-notifying or double-expiring on re-runs |

> **Known gap — superseding a sent quote is not implemented.** The `quotes` table has a
> `superseded` status and a `superseded_by_id` column, and every read path already honours them
> (the portal list and `company_quote()` both filter `superseded` out), but **no code path ever
> writes them**. There is also no `transition_quote()` — the only quote transitions a user can
> trigger are customer acceptance, customer rejection, and cron expiry.
>
> Practical effect: once a quote is `sent`, an admin cannot revise or replace it. The RFQ is
> blocked from re-quoting (`obbp_quote_exists`) until the customer accepts or rejects it, or the
> daily cron expires it. To change pricing after sending, the workflow today is: wait for
> expiry, or reject from the customer side, then create a fresh quote.
>
> This is a design-intent gap, not a regression — the schema and read paths are already correct,
> so implementing it means adding a supersede transition that stamps the old quote `superseded`
> plus `superseded_by_id` and returns the new draft's ID.

---

## 5. Purchase orders

| Case | Behaviour | Prevents |
| --- | --- | --- |
| Issuing a non-draft PO | `obbp_po_not_draft` | Duplicate issue emails and timestamps |
| Two admins issuing at once | Second one sees the locked `issued` status and returns `obbp_po_not_draft` | Two "issued" audit entries |
| Acknowledging someone else's PO | `obbp_po_unavailable` | Confirming another company's order |
| Illegal transition (e.g. `draft` → `delivered`) | Rejected by the transition map | Skipping fulfilment steps that drive reporting |
| Email transport is down | Mail is sent after `COMMIT`, so the order is still saved | A failed SMTP call rolling back a confirmed order |

Valid PO path: `draft` → `issued` → `confirmed` / `acknowledged` → `in_production` →
`dispatched` → `delivered` → `completed` → `closed`. `cancelled` is reachable from every
pre-delivery state; `delivered` may only go to `completed`.

---

## 6. CSV import

| Case | Behaviour | Prevents |
| --- | --- | --- |
| Missing required column | Whole import refused before any write | Partial imports that look successful |
| Duplicate or empty header names | Import refused | Rows silently mapped to the wrong column |
| UTF-8 BOM on the first header | Stripped | `\xEF\xBB\xBFname` failing to match `name` |
| One bad row | That row is reported and skipped; the rest import | A single typo discarding a whole file |
| Row count mismatch | Trailing empty cells trimmed, short rows padded, genuine overflow rejected | Excel/Sheets trailing commas silently skipping every row |
| Re-importing the same catalog file | Matched on `sku` and updated in place | Duplicate products on every re-import |
| `min_qty` / `qty_step` below 1 | Floored to 1 | Storing 0 for a value that passed a `> 0` check |
| Invalid currency on a catalog row | Falls back to the site default | A product with no currency |
| Blank `slug` on a price list | Derived from the name | Hand-written CSVs rejected for a derivable field |
| Re-importing price list items | Upsert on `UNIQUE KEY list_product`; `id` and `created_at` preserved | `REPLACE` resetting row identity and creation dates |
| `price_list_id = 0` on a company | Stored as `NULL` — use the default list | A company pinned to list 0 |
| Company currency ≠ price list currency | `obbp_currency_mismatch` | A company whose currency contradicts its own price list |
| Missing or inactive `price_list_id` | `obbp_price_list_invalid` | Companies pinned to a withdrawn list |
| Leading `=`, `+`, `-`, `@` in exported text | Prefixed with `'` — **except** numeric values | Spreadsheet formula injection, and negative numbers being mangled on re-import |
| File over 5 MB | Refused | Uploading an unbounded file |
| Non-upload / missing file | Refused | Reading arbitrary paths |

Notes and limits:

- `status` is ignored unless *Apply company status* is ticked; see README for the exact
  semantics and why.
- Price-item import is **additive**: rows absent from the CSV keep their current `is_active`.
  The admin *Save items* screen, by contrast, deactivates items omitted from its submission.
  The two behaviours are intentionally different.
- There is no row-count ceiling. A 5 MB file can mean tens of thousands of rows in one request.
  The endpoint is capability-gated, but consider splitting very large files.

---

## 7. Authorisation and input handling

| Case | Behaviour |
| --- | --- |
| Unknown `obbp_do` action | Rejected — actions come from a per-action capability whitelist |
| Valid action, missing or stale nonce | `wp_die` on the nonce check |
| Valid action, insufficient capability | Rejected on the capability check |
| Portal POST while logged out | Rejected before any workflow call |
| `?obbp_view=` set to garbage or a traversal string | Falls back to the default view |
| `obbp_message` / `obbp_error` in the query string | `sanitize_text_field` then `esc_html` |
| RFQ, quote, or PO ID belonging to another company | `company_*()` returns `null`; treated as not found |
| `orderby` supplied to a repository query | Passed through `sanitize_sql_orderby()` |
| `limit` / `offset` supplied to a repository query | Clamped (`limit` to 1000, `offset` to ≥ 0) |
| Activity log growth | Export is capped at the 10 000 most recent rows |

---

## 8. Uninstall

| Case | Behaviour |
| --- | --- |
| Roles `obbp_manager` / `obbp_sales` / `obbp_customer` | Always removed |
| `obbp_*` capabilities on `administrator` | Always removed |
| *Delete data on uninstall* **off** | Tables, catalog posts, options, and portal logins are left intact |
| *Delete data on uninstall* **on** | Tables dropped, `obbp_product` posts deleted, plugin-created users deleted |
| A plugin-created user has authored other content | User is kept — only pure portal accounts are deleted |
| A drop or delete fails | Remaining cleanup is abandoned and options are preserved, rather than leaving a half-deleted install looking clean |
