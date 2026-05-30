=== Paymos for WooCommerce ===
Contributors: paymos
Tags: woocommerce, payments, crypto, paymos, checkout-blocks
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Official Paymos payment gateway for WooCommerce.

== Description ==

Paymos for WooCommerce creates a Paymos invoice during checkout and redirects
the customer to the hosted Paymos payment page.

The plugin supports:

* Classic WooCommerce checkout.
* WooCommerce Checkout Blocks.
* WooCommerce High-Performance Order Storage (HPOS).
* One dashboard-generated ZIP archive with embedded sandbox and live Paymos credentials.
* Signed Paymos webhooks with timestamp validation and event_id deduplication.
* Reverse API verification for terminal invoice webhooks before changing order state.
* Automatic 10-minute reconciliation for missed webhooks.
* Amount-change protection before marking WooCommerce orders paid.
* Paymos payment details on the WooCommerce order admin page.

== Requirements ==

* WordPress 5.9 or newer.
* WooCommerce 6.0 or newer.
* PHP 7.4 or newer.
* A Paymos project.

== Installation ==

1. In Paymos Dashboard, open CMS and select WooCommerce.
2. Make sure the project that should receive WooCommerce invoices is selected, then enter the public HTTPS store URL.
3. Click Download plugin to fetch the generated ZIP.
4. Upload the generated ZIP through WordPress Plugins.
5. Activate the plugin.
6. Open WooCommerce -> Settings -> Payments -> Paymos.
7. Enable Paymos payments and choose Sandbox or Live mode.

Dashboard-generated plugin archives include `paymos-config.php`, so merchants
do not paste API keys, API secrets, project IDs, webhook secrets, or base URLs
manually.

The Paymos API host defaults to `https://api.paymos.io`. It is not a merchant
setting. Paymos may override it in generated archives for sandbox, staging, or
internal builds.

== Webhook URL ==

The plugin receives Paymos webhooks at:

`/wp-json/paymos/v1/webhook`

The exact full URL is shown in WooCommerce -> Settings -> Payments -> Paymos.
Dashboard-generated ZIPs use the store URL to register sandbox and live
project-scoped invoice webhooks in Paymos automatically.

== Security ==

Webhook requests must include `X-Webhook-Signature`.

The Paymos PHP SDK verifies the signature as HMAC-SHA256 over:

`timestamp.raw_body`

The plugin tries the sandbox and live webhook secrets from `paymos-config.php`.
The secret that verifies the signature determines the authenticated environment.
The plugin rejects invalid signatures, stale timestamps, duplicate committed
`event_id` values, project mismatches, and environment mismatches before
changing any WooCommerce order.

For terminal invoice statuses (`paid`, `paid_over`, `underpaid`, `expired`,
and `cancelled`), the plugin also calls the Paymos API to fetch the current
invoice state before changing the WooCommerce order. The event is committed to
the dedup store only after the order update succeeds, so a failed processing
attempt can still be retried by Paymos.

== Reconciliation ==

The plugin schedules a WordPress cron task every 10 minutes. It scans unpaid
Paymos orders, fetches the current invoice status from Paymos, and applies the
same status mapping as the webhook handler.

This recovers orders when a webhook delivery was missed because the store was
temporarily unavailable, blocked by infrastructure, or failed during processing.

== Amount Protection ==

When the plugin creates a Paymos invoice, it stores the WooCommerce order total
and currency as a snapshot. If a paid webhook arrives after the WooCommerce
order amount or currency changed, the plugin keeps the order on hold and adds a
manual review note instead of calling `payment_complete`.

This prevents an old invoice, for example 100.00 USD, from completing an order
that was later changed to 120.00 USD.

== Logs ==

Enable Debug logging in the Paymos payment method settings to write Paymos
entries into WooCommerce logs. API keys and secrets are redacted.
