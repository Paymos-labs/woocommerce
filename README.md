# Paymos for WooCommerce — accept USDT and USDC at checkout

WooCommerce payment gateway for stablecoin payments. Customer pays in USDT or USDC across 13 mainnet networks. Native stablecoin settlement, no auto-conversion.

**Two-minute setup**: the ZIP you download from [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms) ships with your API keys pre-injected and your webhook endpoint pre-registered. No copy-paste, no manual configuration, no separate dashboard trip after install.

[![WooCommerce 8.0+](https://img.shields.io/badge/WooCommerce-8.0%2B-96588a)](https://woocommerce.com/)
[![WordPress 5.9+](https://img.shields.io/badge/WordPress-5.9%2B-21759b)](https://wordpress.org/)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://www.php.net/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)](LICENSE)

- Full documentation: [paymos.io/docs/cms-woocommerce](https://paymos.io/docs/cms-woocommerce)
- Product page: [paymos.io/product/plugins/woocommerce](https://paymos.io/product/plugins/woocommerce)
- Get the plugin ZIP: [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms)

---

## Why this is two minutes, not two hours

Other plugins ask you to:
- Create an API key in their dashboard
- Copy it into your store's plugin settings
- Configure the webhook URL
- Paste a signing secret
- Test the webhook handshake yourself

The Paymos plugin ZIP **already contains all of that**, generated on the dashboard at download time:

- Sandbox + Live API credentials — baked into `paymos-config.php` inside the ZIP
- Webhook endpoint URL — pre-registered against your store domain
- Signing secret — same, server-side, never shown to you
- Mode switch (Sandbox/Live) — pre-wired, defaults to Sandbox

You install the ZIP, flip a mode switch, place a test order. That's the whole setup.

---

## Install — full walkthrough

### Step 1: Sign in to Paymos (≈30 sec)

1. Go to [paymos.io/login](https://paymos.io/login).
2. Email magic-link **or** Google — no password, no documents.
3. Onboarding wizard, 3 required steps: business name, country, integration pick.
4. Pick **CMS plugin → WooCommerce**.
5. You land on [paymos.io/dashboard/quickstart](https://paymos.io/dashboard/quickstart).

### Step 2: Download the plugin (≈10 sec)

1. Open [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms).
2. Pick **WooCommerce**.
3. Click **Download ZIP**.

The ZIP is generated server-side at this moment — your keys, your webhook URL, your domain. Pre-configured, ready to install.

### Step 3: Install in WordPress (≈40 sec)

1. WordPress admin → **Plugins → Add New → Upload Plugin**.
2. Select the ZIP → **Install Now**.
3. **Activate Plugin**.
4. **WooCommerce → Settings → Payments → Paymos** → toggle **Enabled**.

You'll see `Paymos for WooCommerce` in your active plugin list. Configuration is empty by design — credentials live in `paymos-config.php`, locked.

### Step 4: Verify with a sandbox order (≈40 sec)

1. Confirm **Mode: Sandbox** in WooCommerce → Settings → Payments → Paymos.
2. Open your storefront → add any product to cart → checkout.
3. Pick **Paymos** at payment selection.
4. On the hosted Paymos page, click **Simulate payment**.
5. WordPress admin → Orders → status should flip to your mapped "paid" state within ~5 seconds.

Working? Switch to **Mode: Live**. Done.

Onboarding reference: [paymos.io/dashboard/quickstart](https://paymos.io/dashboard/quickstart).

---

## Requirements

- WooCommerce **8.0+**
- WordPress **5.9+**
- PHP **7.4+**
- HTTPS on checkout (TLS 1.2+)

High-Performance Order Storage (HPOS) and Checkout Blocks both supported.

---

## Runtime flow

1. Customer reaches WooCommerce checkout, selects Paymos.
2. Plugin creates a Paymos invoice via the Merchant API using the order total and currency.
3. Customer is redirected to the hosted Paymos page (or sees the embedded checkout block on the same domain).
4. Customer pays in USDT or USDC on a supported chain.
5. Paymos confirms the on-chain payment using a tiered policy — small tickets clear in seconds, large tickets wait for more confirmations.
6. Paymos sends a signed webhook to your store. Plugin verifies signature, timestamp, and amount, then marks the order paid.
7. Customer lands on your success page.

If the webhook is lost, the reconciler polls Paymos every 10 minutes for unresolved invoices.

Reference: [paymos.io/docs/payment-flow](https://paymos.io/docs/payment-flow).

---

## Configuration

The ZIP pre-fills everything technical. WooCommerce admin only exposes presentation choices:

| Setting | What it controls |
|---|---|
| Mode | `Sandbox` for tests, `Live` for production. Switch without re-uploading. |
| Title | Customer-facing label at checkout. |
| Description | Short blurb under the title at checkout. |
| Status mappings | Map Paymos invoice states to WooCommerce order states. |
| Logging | Enable verbose logs in `WooCommerce → Status → Logs`. |

Credentials are **read-only inside the plugin** — they live in `wp-content/plugins/paymos-woocommerce/paymos-config.php` and were written by the dashboard ZIP. If you ever need to regenerate them, [download a fresh ZIP](https://paymos.io/dashboard/cms) and reinstall.

---

## Webhooks — pre-registered, no setup

The dashboard registers your webhook endpoint against your store domain **before the ZIP is generated**. The plugin's webhook URL is:

```
https://your-store.com/?wc-api=paymos_webhook
```

You will not need to set this up yourself. You also will not need to handle the signing-secret rotation — the plugin reads it from `paymos-config.php` and rolls with whatever the dashboard ships.

Manage and replay events at [paymos.io/dashboard/developers/webhooks](https://paymos.io/dashboard/developers/webhooks).

Plugin verifies every incoming webhook:

- **Signature** — header `X-Webhook-Signature`, format `t={timestamp},v1={hex}`, algorithm HMAC-SHA256, timing-safe compare, ±5 min timestamp tolerance (parsed from the `t=` component, rejects replays).
- **Event ID deduplication** — same `event_id` cannot mark the same order paid twice.
- **Amount match** — pulls the live invoice from the Paymos API and confirms the on-chain amount matches the order total.

Any check fails → HTTP 4xx response → order is **not** updated.

Retry policy on the Paymos side: **11 attempts** with exponential backoff over ~32 hours (1m, 2m, 4m, 8m, 16m, 32m, 1h, 2h, 4h, 8h, 16h). Failed webhooks land in the dashboard for manual replay.

Signature verification deep-dive: [paymos.io/docs/webhooks/verify](https://paymos.io/docs/webhooks/verify).
Retry schedule: [paymos.io/docs/webhooks/retry](https://paymos.io/docs/webhooks/retry).

---

## Sandbox testing

Sandbox is fully wired the moment you install the plugin. No whitelist, no extra approval, no separate "sandbox dashboard" trip.

1. Switch **Mode: Sandbox** in settings.
2. Place a test WooCommerce order.
3. On the hosted Paymos page, hit **Simulate payment**.
4. Order should flip to your mapped "paid" state within ~5 seconds.

Same API surface as Live. Same webhook schema. Sandbox uses testnet credentials shipped in the same ZIP, Live uses mainnet.

Sandbox guide: [paymos.io/docs/testing](https://paymos.io/docs/testing).

---

## FAQ

**Why does the ZIP have everything pre-configured?**
Because the dashboard generates it that way. At download time, the server reads your merchant record, creates Sandbox and Live credentials if missing, registers the webhook endpoint against your store domain, and writes everything into `paymos-config.php` before zipping. You get a turn-key bundle.

**Do I ever need to paste an API key?**
No. The plugin reads everything from `paymos-config.php`. WordPress admin never asks you for a key.

**What if I need to rotate the signing secret?**
Re-download the ZIP from [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms) and reinstall. The dashboard supports rolling rotation — the previous secret stays valid through a grace window so in-flight webhooks don't fail.

**Does this plugin work with WooCommerce Checkout Blocks?**
Yes. Both Classic Checkout and Checkout Blocks render the Paymos option.

**Does it work with WooCommerce Subscriptions?**
Not at first release. Recurring billing is best handled via the [WHMCS plugin](https://github.com/paymos-labs/whmcs) or a custom integration on the [Paymos Merchant API](https://paymos.io/docs/quick-start).

**What happens if a customer pays late?**
Paymos rejects the on-chain transaction at the domain boundary. The order stays unpaid, the customer can retry.

**Are there chargebacks?**
No. Crypto settlement is final on confirmation.

**What if the webhook never arrives?**
The reconciler polls Paymos every 10 minutes for unresolved invoices. Orders catch up automatically. You can also force-check a single order from the plugin admin, or replay any event from [paymos.io/dashboard/developers/webhooks](https://paymos.io/dashboard/developers/webhooks).

**Which network is cheapest for the customer?**
The customer picks the network on the hosted Paymos page — they see live gas before paying.

**Is HPOS supported?**
Yes. The plugin reads and writes orders through the HPOS-aware API.

---

## Troubleshooting

| Symptom | What to check |
|---|---|
| Plugin not showing under WC → Payments | WooCommerce version below 8.0, or plugin not activated. Re-check **Plugins → Installed Plugins** for `Paymos for WooCommerce`. |
| Settings page empty / credentials missing | `paymos-config.php` not written. The ZIP wasn't generated by the dashboard — re-download from [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms) and reinstall. |
| Order stays `pending-payment` after customer paid | `WooCommerce → Status → Logs` → look for `paymos` source. Signature failure = sandbox/live mode mismatch between the plugin and the dashboard. |
| `Paymos gateway is not available` at checkout | Currency mismatch. Store currency must be a Paymos-supported invoicing currency. |
| Customer sees `Sandbox mode` banner in production | Mode toggle still on Sandbox. |
| `Signature verification failed` in logs | Plugin and dashboard configs out of sync. Re-download the ZIP from [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms) and reinstall. |
| Webhook never arrives | Check WP `wp-cron` is running. If your host disables it, set up a real cron hitting `wp-cron.php` every 5 minutes. |

Error reference: [paymos.io/docs/errors](https://paymos.io/docs/errors).

---

## Support

- Documentation: [paymos.io/docs/cms-woocommerce](https://paymos.io/docs/cms-woocommerce)
- Dashboard: [paymos.io/dashboard](https://paymos.io/dashboard)
- Status: [paymos.io/status](https://paymos.io/status)
- Issues: [github.com/paymos-labs/woocommerce/issues](https://github.com/paymos-labs/woocommerce/issues)
- Email: [support@paymos.io](mailto:support@paymos.io)

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) — or browse the public release history at [paymos.io/changelog](https://paymos.io/changelog).

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). Matches WordPress and WooCommerce licensing.
