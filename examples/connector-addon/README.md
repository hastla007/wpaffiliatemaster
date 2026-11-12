# Custom app connector starter

This example plugin shows how an add-on can report paid sales and refunds from a custom app to WP Affiliate Captain without accessing its database tables.

## Install

1. Copy this folder into `wp-content/plugins/` on a WordPress site that runs WP Affiliate Captain.
2. Activate **WP Affiliate Captain Connector Example**.
3. Replace the example hooks `my_app_order_paid` and `my_app_order_refunded` in the PHP file with the hooks exposed by your app or its WordPress connector.
4. Map the app's order/refund data to the payloads below.

This is an integration template. The example hook names are placeholders, so no sales are sent until you connect them to real application events.

## Paid order payload

The paid-order hook receives an array with:

```php
array(
    'order_id'     => 'order-4711',       // stable source ID
    'currency'     => 'EUR',               // ISO-4217
    'paid_at'      => time(),              // timestamp or date string
    'hold_until'   => '2026-12-11 00:00:00', // optional; warranty/withdrawal end
    'partner_code' => 'SEGELSCHULE-NORD',  // optional; cookie-free attribution
    'touch'        => null,                // optional: code, method, campaign, content_id
    'customer'     => array(
        'user_id' => 42,                   // optional
        'email'   => 'buyer@example.com',  // optional; used only for self-referral check
    ),
    'items'        => array(
        array(
            'item_id'      => 'line-1',    // stable ID, needed to match refunds
            'product_id'   => 55,           // optional
            'parent_id'    => 0,            // optional
            'sku'          => 'COURSE-SEA', // optional; may be a course code
            'category_ids' => array(),      // optional
            'tag_ids'      => array(),      // optional
            'name'         => 'Sea course', // optional
            'quantity'     => 1,
            'net_minor'    => 49900,        // integer cents, after discounts, excl. VAT/shipping
        ),
    ),
);
```

Call the paid-order hook only after payment has succeeded. Keep the same source order ID on retries: WP Affiliate Captain uses it for idempotency.

## Refund payload

```php
array(
    'order_id'  => 'order-4711',
    'refund_id' => 'refund-1',             // stable and unique per refund
    'type'      => 'refund',               // refund|cancellation|chargeback
    'full'      => false,
    'items'     => array(
        array( 'item_id' => 'line-1', 'net_minor' => 10000 ),
    ),
    'reason'    => 'Partial refund',
);
```

A full cancellation or chargeback reverses the remaining commission. Partial refunds must use the original sale's item IDs and the refunded net amount for each item.

## Integration rules

- Use the public `wpac_record_sale()` and `wpac_record_refund()` functions only after `wpac_loaded`.
- Never read or write `wpac_*` database tables directly.
- Send integer minor units and a three-letter currency code.
- Keep `order_id`, `refund_id`, and item IDs stable.
- Send only the customer user ID and email needed for self-referral checks. Do not pass payment details or full order objects.
- Store the referral code or touch with the app's order at checkout and include it when reporting the later payment. Do not rely on cookies for cookie-free attribution.
- For app orders, choose a stable source slug and use it for both sales and refunds.

See [the public integration contract](../../docs/CONTRACT.md) and [REST API](../../docs/REST.md) for the full schema and behavior.
