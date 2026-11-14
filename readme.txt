=== Paymos for WooCommerce ===
Contributors: paymos
Tags: payments, stablecoin, usdt, usdc, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept stablecoin payments with Paymos hosted checkout and signed webhooks.

== Description ==

Paymos for WooCommerce supports Sandbox and Live payments, HMAC-signed Merchant API requests, authenticated webhooks, event deduplication, reconciliation, HPOS, and Checkout Blocks.

The official package contains no merchant secrets. Connect it from WooCommerce settings using the one-time Paymos device approval flow. Credentials are encrypted with AES-256-GCM using WordPress installation security salts and are never rendered back into the settings page.

== External services ==

This plugin connects your WooCommerce store to Paymos. Click **Connect Paymos** in the store admin to begin. The plugin starts the device flow at `https://app.paymos.io/connect/cms/device` and polls `https://app.paymos.io/connect/token`. Your browser opens the Paymos approval page with the plugin identifier, store origin, admin return URL, and `user_code`. The secret `device_code` goes from the plugin to the token endpoint. After approval, the plugin stores Sandbox and Live credentials in WordPress options encrypted with AES-256-GCM, then discards the short-lived OAuth token.

The plugin calls `https://api.paymos.io/v1/invoices` to create invoices, `GET /v1/invoices/{invoice_id}` to check status, and `POST /v1/invoices/{invoice_id}/cancel` to cancel them. Requests carry a key ID, timestamp, and HMAC signature; the API secret signs requests locally. Invoice data includes `project_id`, `amount`, `currency`, and `external_order_id`, plus the native customer ID as `client_id` when available. The WooCommerce order reference includes the order access key.

For refunds, the plugin sends `POST /v1/refunds` requests to `https://api.paymos.io/v1/refunds` with only `invoice_id`, `mode` (`full` or `overpayment`), and `external_order_id`; Paymos returns funds strictly to the verified `first_sender` address recorded on the invoice. The plugin checks individual refund status with `GET /v1/refunds/{refund_id}`, polls cursor-paginated refund pages with `GET /v1/refunds` (`cursor`, `limit`, `invoice_id`, and `status`), and in Sandbox mode simulates terminal outcomes with `POST /v1/sandbox/refunds/{refund_id}/simulate` sending `status` (`completed`, `failed`, or `cancelled`). Unresolved invoices are polled by the `paymos_reconcile_orders` WP-Cron job, and pending refunds are reconciled by a 1-minute WP-Cron job (`paymos_woocommerce_refunds_reconcile`).

Buyers open the hosted payment page at `https://checkout.paymos.io` over HTTPS. Paymos sends signed invoice and payment webhooks back to your store so the plugin can update the order.

Read the [Paymos Terms](https://paymos.io/terms) and [Privacy Policy](https://paymos.io/privacy) before connecting.

== Installation ==

1. Install the official plugin package and activate it.
2. Open WooCommerce -> Settings -> Payments -> Paymos.
3. Click Connect Paymos and approve the current project in the Paymos dashboard.
4. Select Sandbox or Live mode and enable the payment method.

== Frequently Asked Questions ==

= Does the plugin ZIP contain my API secrets? =

No. Official release packages are identical for every merchant and contain no merchant credentials.

= Where are credentials stored? =

In a non-autoloaded WordPress option encrypted with AES-256-GCM. Key material is derived from this WordPress installation's security salts.

= Which project is connected? =

The project currently selected in Paymos when you approve the connection. There is no second project selector.

= Does OAuth replace Merchant API HMAC authentication? =

No. OAuth device authorization is only a one-time credential delivery channel. Runtime Merchant API calls remain HMAC signed.

== Changelog ==

= 1.7.6 =
* fix(woocommerce,edd): a network error during Connect keeps the flow alive

= 1.7.5 =
* chore: rebuild canonical CMS package

= 1.7.4 =
* chore: rebuild canonical CMS package

= 1.7.3 =
* chore: bundle Paymos PHP SDK v1.10.2

= 1.7.2 =
* chore: rebuild canonical CMS package

= 1.7.1 =
* chore: bundle Paymos PHP SDK v1.10.1
* chore: rebuild canonical CMS package

= 1.7.0 =
* fix(plugins): unblock WordPress checks and align native recovery tests
* feat(notifications): rewrite all 71 email and 52 Telegram RU templates, lifecycle series, and R01-R34 audit fixes
* chore: bundle Paymos PHP SDK v1.10.0
* chore: rebuild canonical CMS package

= 1.6.5 =
* chore: rebuild canonical CMS package

= 1.6.4 =
* chore: rebuild canonical CMS package

= 1.6.3 =
* chore: rebuild canonical CMS package

