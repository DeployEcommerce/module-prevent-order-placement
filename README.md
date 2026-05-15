# DeployEcommerce_PreventOrderPlacement

Block Magento 2 order placement when a customer's billing or shipping address
matches an admin-configured blocklist. Built to stop repeat-offender fraud
patterns (drop addresses, recycled phone numbers) at the `placeOrder` boundary
before an order is created.

- **Module**: `DeployEcommerce_PreventOrderPlacement`
- **Composer**: `deployecommerce/module-prevent-order-placement`
- **License**: [MIT](LICENSE.md)
- **Tested with**: Magento 2.4.6 (Enterprise) on PHP 8.1

---

## Features

- **Admin-managed blocklist** with one rule per row. Each row may populate any
  subset of `street`, `city`, `county`, `postcode`, `phone` and choose whether
  the rule applies to the **billing**, **shipping**, or **both** address sides.
- **AND semantics within a row**: all populated fields must match for the row
  to fire. Empty fields are ignored.
- **Substring matching** on lowercase-normalised values, so admin-entered
  fragments still catch realistic address variants. Phone values are compared
  digit-only on both sides, so `+44 7700 900123`, `07700 900123` and
  `(07700) 900-123` are all matched by the same rule.
- **Generic customer-facing error**: blocked customers see a non-specific
  message ("Your order cannot be placed. Please contact customer support.") so
  no detection signal is leaked to fraudsters.
- **Audit log table** records every block with the input data compared, full
  billing + shipping snapshots, the matched rule, scope, failure reason, IP,
  quote id, customer id/email, reserved increment id, and the payment method
  in use at the time. Survives even if downstream order creation logic changes.
- **Admin preview** of how many existing records a rule would match.
  Before saving, blurring a row sends an AJAX request that scans the last 12
  months of `sales_order_address` plus all active `quote_address` rows, and
  renders a colour-coded inline note:
  - 🟢 Green when both percentages are < 5 %
  - 🟠 Orange between 5 % and 10 %, or when the rule is too loose to estimate
    (e.g. a single-character field)
  - 🔴 Red above 10 %
- **Per-store kill switch**: a Yes/No toggle at Store View scope lets admins
  disable the feature anywhere it misbehaves without touching the rules.
- **Defaults to off**, so deploying the module is inert until an admin opts in.

---

## Installation

### Via Composer (recommended)

```bash
composer require deployecommerce/module-prevent-order-placement
bin/magento module:enable DeployEcommerce_PreventOrderPlacement
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Via `app/code`

Drop the module under `app/code/DeployEcommerce/PreventOrderPlacement/`, add
`'DeployEcommerce_PreventOrderPlacement' => 1,` to `app/etc/config.php`, then:

```bash
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

The declarative schema creates one new table:
`deployecommerce_preventorderplacement_blocked_attempt`.

---

## Configuration

After installation the module appears under
**Stores → Configuration → Deploy Ecommerce → Prevent Order Placement**.

### General

| Setting | Scope | Default | Description |
| --- | --- | --- | --- |
| Enabled | Store View | No | Master switch. When **No**, the order-placement check is skipped entirely on the affected store view. |

### Address Blocklist → Rules

Click **Add Rule** for each blocklist entry. Within a row, fill the fields
you want matched and leave the rest blank; all populated fields must match
(AND) for the rule to fire. Address Scope picks which side of the address is
checked.

| Field | Notes |
| --- | --- |
| Street | Substring match (case-insensitive). |
| City | Substring match (case-insensitive). |
| County | Substring match against the address's `region` field. |
| Postcode | Substring match (case-insensitive). |
| Phone | Compared digit-only on both sides, so formatting doesn't matter. |
| Address Scope | `Billing`, `Shipping`, or `Both`. |

Values are normalised at save time (trimmed, lowercased, phone reduced to
digits). The preview note under each row tells you how many existing records
the rule would have matched in the last 12 months — keep an eye on it: rules
with a high match percentage are almost always too loose.

#### Example rule

| Street | City | County | Postcode | Phone | Address Scope |
| --- | --- | --- | --- | --- | --- |
| `1 Example Street` | | | `ZZ1 1ZZ` | | Shipping |

Blocks any order whose shipping address contains both `1 Example Street`
**and** `ZZ1 1ZZ`. A second rule covering just the postcode would catch
formatting variants of the same drop address.

---

## How it works

A plugin on `Magento\Quote\Api\CartManagementInterface::placeOrder` runs
before the order is created and:

1. Bails immediately if the feature is disabled for the quote's store view
   (using the current request's store, so no quote/config work happens on
   stores where the feature is off).
2. Loads the configured rule list. Each rule's fields are pre-normalised at
   save time, so the runtime can do a cheap lowercase `str_contains` /
   digit-only comparison.
3. For each rule, pulls the billing and/or shipping address from the quote
   (per the rule's scope) and applies all populated criteria with AND
   semantics. The first matching rule wins.
4. On match: writes an audit row to
   `deployecommerce_preventorderplacement_blocked_attempt`, logs a
   `WARNING` line, and throws a generic `LocalizedException` so the customer
   sees no detection signal.

The plugin uses `?PaymentInterface $paymentMethod = null` (explicit nullable)
to remain warning-clean on PHP ≥ 8.4 while still matching the implicit-nullable
signature on the wrapped Magento interface.

---

## Audit table

`deployecommerce_preventorderplacement_blocked_attempt`

| Column | Notes |
| --- | --- |
| `entity_id` | Primary key. |
| `created_at` | Time of attempt. |
| `remote_ip` | Customer IP (IPv4 or IPv6). |
| `quote_id` | The quote at the moment of attempt. |
| `reserved_order_id` | Magento increment ID if reserved by the checkout flow; null otherwise. The plugin never forces reservation, so there's no increment-sequence gap. |
| `payment_method` | The payment method code in use at the moment of block. |
| `customer_id` | Customer id (null for guest checkout). |
| `customer_email` | Customer or billing email. |
| `input_data` | JSON of the lowercased / digit-only values that were actually compared. |
| `addresses_json` | JSON snapshot of the full billing + shipping address rows. |
| `matched_rule` | JSON of the rule that fired (post-normalisation). |
| `matched_scope` | `billing` or `shipping`. |
| `failure_reason` | Human-readable string listing each rule field that matched and the value it matched against. |

There is intentionally no admin grid in this release. The table is queryable
directly (phpMyAdmin / `bin/mysql`) and is the canonical record of why an
order was refused.

---

## Tests

The module ships with a PEST suite (PHPUnit 9.6 under the hood) covering the
matcher, the config-backend normalisation, and the preview estimator
(including its SQL filter shape).

```bash
vendor/bin/pest -c app/code/DeployEcommerce/PreventOrderPlacement/Tests/Unit/phpunit.xml.dist
```

All fixtures use reserved-fictional values (`ZZ1 1ZZ` from Royal Mail's
test-postcode range, `07700 900xxx` from Ofcom's drama-use phone range) so
no production data is embedded in the test suite.

---

## Troubleshooting

- **Section doesn't appear in admin sidebar** — confirm the module is enabled
  (`bin/magento module:status DeployEcommerce_PreventOrderPlacement`) and
  the admin user's role has access to
  `DeployEcommerce_PreventOrderPlacement::config` (granted automatically to
  full-access roles).
- **Legitimate orders being blocked** — find the row in
  `deployecommerce_preventorderplacement_blocked_attempt`; the
  `failure_reason` column lists the exact rule fields that matched. Either
  tighten the rule (add more fields so AND requires more specificity) or
  flip the per-store **Enabled** switch to No while you triage.
- **Preview always shows orange "too loose to estimate"** — a populated rule
  field is shorter than two characters. Lengthen the input; the gate exists
  to stop per-blur full-table scans on the historical data set.
- **Preview times out** — the 12-month order window covers
  `~131k` rows on a typical mid-volume store; if your `sales_order_address`
  is much larger you may want to add a covering index on `created_at`.
