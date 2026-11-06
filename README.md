# Paymos for WooCommerce

Official WooCommerce gateway for Paymos. It adds a stablecoin method to your
checkout, hands the buyer to the hosted Paymos checkout page, and moves the
WooCommerce order from a signed webhook. The buyer chooses the token and network
from the set enabled on your Paymos project. The order total crosses in your
store's own currency, and the rate is fixed at the moment the buyer pays, so the
amount that lands is the amount you invoiced.

Nothing in the order lifecycle depends on the buyer returning to your site. A
closed tab, a dead phone battery and a browser crash all end the same way: the
webhook arrives and the order updates.

## Requirements

- WordPress 6.5 or later (tested to 7.0);
- WooCommerce 8.0 or later (tested to 10.9);
- PHP 7.4 or later with the `curl`, `hash`, `json` and `openssl` extensions;
- a publicly reachable HTTPS store URL, so the webhook can be delivered;
- a Paymos account with the receiving project open in the dashboard.

The Paymos PHP SDK ships inside the package. Composer is not needed on the store.
High-Performance Order Storage and Checkout Blocks are both declared compatible.

## Install and connect

1. Download the latest official package from [GitHub Releases](https://github.com/paymos-labs/woocommerce/releases/latest), or from the **CMS integration** panel in your Paymos dashboard.
2. Upload and activate it in **Plugins → Add New → Upload Plugin**.
3. Open **WooCommerce → Settings → Payments → Paymos**.
4. Click **Connect Paymos**. A new tab opens Paymos for approval.
5. Paymos uses the project currently selected in the dashboard. For Sandbox and Live it reuses the merchant's single active Payment key or creates one when absent, then reuses an exact matching Invoice webhook or creates a dedicated webhook for this store URL.
6. Return to WooCommerce and choose Sandbox or Live mode.

The package is identical for every merchant and carries no credentials — no API
key, API secret, project id, webhook secret, OAuth token or device code. Downloading
it connects nothing; approval in step 4 is what provisions the store.

There is no key to paste and no project picker in WooCommerce. The one-time device
authorization response is checked against the plugin type and the store URL, stored
as an AES-256-GCM encrypted WordPress option that is not autoloaded, and the
short-lived OAuth token is thrown away. Merchant API calls are HMAC-signed from
then on. Saved values never render back into the settings HTML and cannot be typed
in by hand — reconnect after a key or webhook secret is rotated.

Webhook URL, registered for you and shown read-only in the settings page:

```text
https://your-store.example/wp-json/paymos/v1/webhook
```

## What the order does

Placing the order creates the invoice, empties the cart and sends the buyer to the
hosted checkout. The order is parked in **On hold** with the note *Awaiting Paymos
payment* before the redirect, so it is never in limbo.

| What arrives | Where the order goes |
|---|---|
| `invoice.confirming` | On hold — the transfer is on chain and gathering confirmations |
| `invoice.underpaid_waiting` | On hold, and the note names the amount still outstanding |
| `invoice.awaiting_payment` | On hold — a counted transfer was rolled back in a reorg |
| `invoice.paid`, `invoice.paid_over` | `payment_complete()` — WooCommerce's own rule then decides Processing, or Completed for an order that is entirely virtual and downloadable |
| `invoice.underpaid` | Failed |
| `invoice.expired`, `invoice.cancelled` | Cancelled |

Two guards sit in front of that table. A paid order is never downgraded by a late
or out-of-order event — the stale status is recorded as a note and dropped. And a
paid event whose amount no longer matches the order is held **On hold** with
*needs manual review* instead of completing, so an edited order total cannot be
settled behind your back.

On completion the transaction hash and explorer link are written to order meta and
shown on the thank-you page, in the customer email, and in the **Paymos Payment**
box on the order screen — alongside the invoice id, the amount snapshot, and the
last event with its id and timestamp.

## Test in Sandbox first

1. Leave **Mode** on Sandbox, enable the method, and place an order against your own store.
2. Open that invoice in the Paymos dashboard. Sandbox payments are not confirmed by a timer — pick the outcome you want to exercise (paid, overpaid, underpaid, cancelled) and the same lifecycle events fire as they would on chain.
3. Watch the WooCommerce order move, and check the order notes for the event trail.
4. Switch **Mode** to Live when the flow reads right. The connection covers both environments, so nothing is reconnected and nothing is set up twice.

Sandbox transfers carry no on-chain evidence, so the transaction hash and explorer
link stay empty there. That is expected and does not mean the mapping is broken.

## Webhooks and the reconciler

Deliveries are signed with `X-Webhook-Signature` (`t={timestamp},v1={hmac_hex}`,
HMAC-SHA256, timing-safe comparison, with a grace window during a secret rotation).
Every event carries a stable `X-Webhook-Id`, and the plugin stores it, so a
redelivery is acknowledged rather than applied twice.

Before any terminal status is written the plugin pulls the invoice back from the
Merchant API and re-checks it, so a forged or replayed body cannot complete an
order on its own.

A WP-Cron job (`paymos_reconcile_orders`) runs every ten minutes as the invoice
safety net. It takes up to 50 Paymos orders still pending or on hold, and up to 50
failed or cancelled ones whose invoice is not final yet, pulls each invoice from
the API and pushes it through the same mapping the webhook uses, guards included.
A separate 1-minute WP-Cron job (`paymos_woocommerce_refunds_reconcile`) reconciles
pending refunds.

## Refunds

The plugin supports native refunds through the Paymos Merchant API (`/v1/refunds`)
in two server-calculated modes: `full` (full invoice refund) and `overpayment`
(refund of the excess on a `paid_over` invoice). Paymos sends the refund as a new
outbound blockchain transaction strictly to the verified `first_sender` address
recorded on the paid invoice — no recipient wallet address is entered manually, and
arbitrary partial refund amounts are not supported.

From the WooCommerce order edit screen, administrators can start a refund in two
ways:

- the native WooCommerce **Refund** button on the order items box (submits a `full`
  refund request);
- the **Paymos Payment** refund panel on the order screen
  (`woocommerce_admin_order_data_after_order_details`), which supports both `full`
  and `overpayment` modes and shows the verified `first_sender` recipient and
  current operation status.

WooCommerce does not book a local `WC_Order_Refund` while the blockchain transfer
is still in flight. Signed webhook callbacks on `/wp-json/paymos/v1/webhook` and
the 1-minute `paymos_woocommerce_refunds_reconcile` WP-Cron job verify the refund
against the Merchant API and record the WooCommerce order refund (or order note for
`overpayment`) once the refund reaches `completed`. In Sandbox mode, terminal
refund outcomes (`completed`, `failed`, `cancelled`) can be simulated for testing.

## Troubleshooting

**The order never leaves On hold.** The store has to be reachable from the public
internet for the webhook to arrive; a staging site behind HTTP auth or a private
network will not receive one. Confirm the site answers on the webhook URL above,
then check **WooCommerce → Status → Logs** with **Debug logging** enabled in the
gateway settings.

**Paid on chain, but the order says the amount needs review.** The order total
changed after the invoice was created. Compare the amount snapshot in the
**Paymos Payment** box against the current order total, and resolve it by hand —
the plugin will not complete an order it cannot reconcile.

**The gateway is missing at checkout.** It hides itself when the active
environment is not fully connected. Open the gateway settings and read the
**Connection status** table; anything showing *Missing* means that environment
never finished connecting.

- [Documentation](https://paymos.io/docs/cms-woocommerce)
- [Source](https://github.com/paymos-labs/woocommerce)
- [Changelog](CHANGELOG.md)
- [Support](mailto:support@paymos.io)
