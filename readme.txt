=== Paymos for WooCommerce ===
Contributors: paymos
Tags: payments, stablecoin, usdt, usdc, crypto, woocommerce
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept stablecoin payments with Paymos hosted checkout and signed webhooks.

== Description ==

Paymos for WooCommerce supports Sandbox and Live payments, HMAC-signed Merchant API requests, authenticated webhooks, event deduplication, reconciliation, HPOS, and Checkout Blocks.

The official package contains no merchant secrets. Connect it from WooCommerce settings using the one-time Paymos device approval flow. Credentials are encrypted with AES-256-GCM using WordPress installation security salts and are never rendered back into the settings page.

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

= 1.0.0 =
* Initial official release.
