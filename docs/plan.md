1. obydullah-b2b-portal
   Purpose
   A customer-facing B2B quotation portal. It should focus on self-service RFQs and quotations, not duplicate the internal accounting and sales features already present in Obydullah Micro ERP.
   MVP
   Admin area

- Company account management
- Account approval/rejection
- Customer contacts
- Product/service catalog
- Customer-specific price lists
- RFQ management
- Quote creation and status management
- Purchase-order tracking
- CSV import/export
- Activity/audit log
  Customer portal
- Company login
- Catalog browsing
- Add multiple items to an RFQ
- Submit delivery date and notes
- View RFQ status
- View and accept quotes
- Download/print quotes
- View purchase-order history
- Reorder previous items
  Workflow
  Pending company
  → Approved company
  → Customer submits RFQ
  → Admin creates quote
  → Customer accepts quote
  → Purchase order created
  → Order history available
  Data structure
  Use a custom post type for the catalog and custom tables for transactional data:
- companies
- company_contacts
- price_lists
- price_list_items
- rfqs
- rfq_items
- quotes
- quote_items
- purchase_orders
- purchase_order_items
- activity_logs
  Implementation order

1. Plugin bootstrap, constants, activation, and database schema
2. Capabilities, roles, settings, and admin menu
3. Company accounts and approval workflow
4. Catalog and price lists
5. RFQ submission
6. Quote lifecycle and customer acceptance
7. Purchase orders and customer history
8. Email notifications and CSV export
9. Security review, documentation, and release
   Exclude from v1

- WooCommerce
- Payments
- Accounting
- Inventory
- Credit scoring
- Complex tax calculation
- Mobile apps
