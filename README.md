# Paymos for WooCommerce

Official WooCommerce gateway for Paymos stablecoin payments.

## Install and connect

1. Download the latest official package from [GitHub Releases](https://github.com/paymos-labs/woocommerce/releases/latest) or install it from the WordPress plugin directory.
2. Activate the plugin.
3. Open **WooCommerce → Settings → Payments → Paymos**.
4. Click **Connect Paymos**. A new tab opens Paymos for approval.
5. Paymos uses the project currently selected in the dashboard. For Sandbox and Live it reuses the merchant's single active Payment key or creates one when absent, then reuses an exact matching Invoice webhook or creates a dedicated webhook for this store URL.
6. Return to WooCommerce and choose Sandbox or Live mode.

The public plugin archive contains no merchant credentials. The one-time device authorization response is validated against the plugin type and store URL, stored as an AES-256-GCM encrypted WordPress option, and the short-lived OAuth token is discarded. Merchant API requests continue to use HMAC authentication.

Webhook URL:

```text
https://your-store.example/wp-json/paymos/v1/webhook
```

Requirements: WordPress 6.2+, WooCommerce 8.0+, PHP 7.4+, OpenSSL.

- [Documentation](https://paymos.io/docs/cms-woocommerce)
- [Source](https://github.com/paymos-labs/woocommerce)
- [Support](mailto:support@paymos.io)
