=== Paymos for WooCommerce ===
Contributors: paymos
Tags: payments, stablecoin, usdt, usdc, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept stablecoin payments with Paymos hosted checkout and signed webhooks.

== Description ==

Paymos for WooCommerce supports Sandbox and Live payments, HMAC-signed Merchant API requests, authenticated webhooks, event deduplication, reconciliation, HPOS, and Checkout Blocks.

The official package contains no merchant secrets. Connect it from WooCommerce settings using the one-time Paymos device approval flow. Credentials are encrypted with AES-256-GCM using WordPress installation security salts and are never rendered back into the settings page.

== External services ==

This plugin connects your WooCommerce store to Paymos. Click **Connect Paymos** in the store admin to begin. The plugin starts the device flow at `https://app.paymos.io/connect/cms/device` and polls `https://app.paymos.io/connect/token`. Your browser opens the Paymos approval page with the plugin identifier, store origin, admin return URL, and `user_code`. The secret `device_code` goes from the plugin to the token endpoint. After approval, the plugin stores Sandbox and Live credentials in WordPress options encrypted with AES-256-GCM, then discards the short-lived OAuth token.

The plugin calls `https://api.paymos.io/v1/invoices` to create invoices, `GET /v1/invoices/{invoice_id}` to check status, and `POST /v1/invoices/{invoice_id}/cancel` to cancel them. Requests carry a key ID, timestamp, and HMAC signature; the API secret signs requests locally. Invoice data includes `project_id`, `amount`, `currency`, and `external_order_id`, plus the native customer ID as `client_id` when available. The WooCommerce order reference includes the order access key.

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

= 1.4.2 =
* fix(plugins): harden WordPress payment recovery and release packages
* chore: bundle Paymos PHP SDK v1.6.1
* chore: rebuild canonical CMS package

* Compare amounts as exact decimals and require complete invoice snapshots plus API confirmation before changing payment state.
* Serialize order updates and recover when native payment completion returns `false` from save or status updates.
* Check ownership when updating event reservations, and purge at most 100 expired records per reservation.
* Continue reconciliation in later batches after the first 50 orders, so older pending orders also get checked.

Fixed:
* When the checkout could not replace an order's invoice (BUG-166), the order
  note read "Paymos payment amount needs manual review." although the block
  also follows a mode or project change, and the buyer read "Paymos payment
  error: unable to create invoice." (BUG-181). The note is now "Paymos payment
  needs manual review." followed by the SDK's summary of the old invoice's
  state, and the buyer reads the SDK's buyer message, "The store needs to
  review this order before payment can continue. Please contact the store."
  Both strings are in the `.pot` and translated in every `.po`/`.mo`.
* A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order is put on hold with a note naming the old
  invoice.
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* The reconciler compared amounts as strings. The snapshot is written with two
  decimals and the server echoes a fiat amount at the currency's own scale, so a
  JPY order (`2500.00` against `2500`) never matched and the fallback for a lost
  webhook skipped it on every run. Amounts are now compared numerically.
* The reconciler re-applied final statuses every ten minutes. Cancelled and
  failed orders were fetched and re-noted on each run — thirty cancellations
  meant thousands of identical notes and API calls a day — and, sharing one
  "newest 50" window with them, an order stuck on hold could fall out of reach.
  Orders whose invoice is already final are no longer fetched, an unchanged
  status writes nothing, waiting orders get their own window, and an invoice
  that was simply not paid yet is no longer described as a rolled-back payment.
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* Paying again for a failed or re-opened order led to a dead invoice. The
  order kept its `external_order_id`, and the server answers a repeated id with
  the same invoice — underpaid, expired or cancelled — so both checkout and
  **Pay invoice** in My Account sent the buyer to a checkout that could no
  longer be paid. Such an order now gets a fresh invoice, **Pay invoice** is
  offered only while the invoice is still open, and the invoice deadline is
  kept on the order.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
* A second invoice could be cut while the first could still be paid. Picking a
  network moves the invoice deadline on the server (`now + PaymentTtl`) and
  sends no webhook, but the order kept the deadline from creation; once that
  passed, paying the order again minted a new `external_order_id` without asking
  the server. The stored id is now always tried first — the server answers it
  with the live invoice — and a new invoice is cut only if the server says the
  old one ended unpaid or was never started. An invoice the server holds open
  (network picked, funds confirming, part paid) is kept, and its status and
  deadline are refreshed on the order. A stored id from another environment or
  project gets a new one, since the server refuses it there.

= 1.4.1 =
* Fix invoice-late-payment audit findings (tasks 4.1-4.8)

Fixed:
* When the checkout could not replace an order's invoice (BUG-166), the order
  note read "Paymos payment amount needs manual review." although the block
  also follows a mode or project change, and the buyer read "Paymos payment
  error: unable to create invoice." (BUG-181). The note is now "Paymos payment
  needs manual review." followed by the SDK's summary of the old invoice's
  state, and the buyer reads the SDK's buyer message, "The store needs to
  review this order before payment can continue. Please contact the store."
  Both strings are in the `.pot` and translated in every `.po`/`.mo`.
* A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order is put on hold with a note naming the old
  invoice.
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* The reconciler compared amounts as strings. The snapshot is written with two
  decimals and the server echoes a fiat amount at the currency's own scale, so a
  JPY order (`2500.00` against `2500`) never matched and the fallback for a lost
  webhook skipped it on every run. Amounts are now compared numerically.
* The reconciler re-applied final statuses every ten minutes. Cancelled and
  failed orders were fetched and re-noted on each run — thirty cancellations
  meant thousands of identical notes and API calls a day — and, sharing one
  "newest 50" window with them, an order stuck on hold could fall out of reach.
  Orders whose invoice is already final are no longer fetched, an unchanged
  status writes nothing, waiting orders get their own window, and an invoice
  that was simply not paid yet is no longer described as a rolled-back payment.
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* Paying again for a failed or re-opened order led to a dead invoice. The
  order kept its `external_order_id`, and the server answers a repeated id with
  the same invoice — underpaid, expired or cancelled — so both checkout and
  **Pay invoice** in My Account sent the buyer to a checkout that could no
  longer be paid. Such an order now gets a fresh invoice, **Pay invoice** is
  offered only while the invoice is still open, and the invoice deadline is
  kept on the order.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
* A second invoice could be cut while the first could still be paid. Picking a
  network moves the invoice deadline on the server (`now + PaymentTtl`) and
  sends no webhook, but the order kept the deadline from creation; once that
  passed, paying the order again minted a new `external_order_id` without asking
  the server. The stored id is now always tried first — the server answers it
  with the live invoice — and a new invoice is cut only if the server says the
  old one ended unpaid or was never started. An invoice the server holds open
  (network picked, funds confirming, part paid) is kept, and its status and
  deadline are refreshed on the order. A stored id from another environment or
  project gets a new one, since the server refuses it there.

= 1.4.0 =
* feat(invoices): accept full late payments without reopening invoices
* chore: bundle Paymos PHP SDK v1.6.0

Fixed:
* When the checkout could not replace an order's invoice (BUG-166), the order
  note read "Paymos payment amount needs manual review." although the block
  also follows a mode or project change, and the buyer read "Paymos payment
  error: unable to create invoice." (BUG-181). The note is now "Paymos payment
  needs manual review." followed by the SDK's summary of the old invoice's
  state, and the buyer reads the SDK's buyer message, "The store needs to
  review this order before payment can continue. Please contact the store."
  Both strings are in the `.pot` and translated in every `.po`/`.mo`.
* A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order is put on hold with a note naming the old
  invoice.
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* The reconciler compared amounts as strings. The snapshot is written with two
  decimals and the server echoes a fiat amount at the currency's own scale, so a
  JPY order (`2500.00` against `2500`) never matched and the fallback for a lost
  webhook skipped it on every run. Amounts are now compared numerically.
* The reconciler re-applied final statuses every ten minutes. Cancelled and
  failed orders were fetched and re-noted on each run — thirty cancellations
  meant thousands of identical notes and API calls a day — and, sharing one
  "newest 50" window with them, an order stuck on hold could fall out of reach.
  Orders whose invoice is already final are no longer fetched, an unchanged
  status writes nothing, waiting orders get their own window, and an invoice
  that was simply not paid yet is no longer described as a rolled-back payment.
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* Paying again for a failed or re-opened order led to a dead invoice. The
  order kept its `external_order_id`, and the server answers a repeated id with
  the same invoice — underpaid, expired or cancelled — so both checkout and
  **Pay invoice** in My Account sent the buyer to a checkout that could no
  longer be paid. Such an order now gets a fresh invoice, **Pay invoice** is
  offered only while the invoice is still open, and the invoice deadline is
  kept on the order.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
* A second invoice could be cut while the first could still be paid. Picking a
  network moves the invoice deadline on the server (`now + PaymentTtl`) and
  sends no webhook, but the order kept the deadline from creation; once that
  passed, paying the order again minted a new `external_order_id` without asking
  the server. The stored id is now always tried first — the server answers it
  with the live invoice — and a new invoice is cut only if the server says the
  old one ended unpaid or was never started. An invoice the server holds open
  (network picked, funds confirming, part paid) is kept, and its status and
  deadline are refreshed on the order. A stored id from another environment or
  project gets a new one, since the server refuses it there.

= 1.3.22 =
* docs(plugins): переводы сообщения о заблокированной замене счёта и сверка минимальных версий
* fix(plugins): BUG-181 WooCommerce и CS-Cart называют настоящую причину и переводят текст покупателю; минимум PHP для OpenCart — 8.0
* fix(plugins): BUG-166 старый счёт закрывается на сервере до выпуска нового; открытый, оплаченный или 404 — в ручную проверку
* fix(woocommerce): BUG-163 второй счёт не выпускается, пока сервер держит первый открытым
* fix(plugins): BUG-103 вебхук, который ещё обрабатывается, больше не отвечается 200 «duplicate»
* fix(plugins): BUG-090 оплата больше не ведёт на истёкший или проваленный счёт Paymos
* fix(plugins): BUG-135 поздний нефинальный вебхук больше не оживляет проваленный или отменённый заказ
* fix(plugins): BUG-133 BUG-134 реконсилятор WooCommerce сверяет суммы численно и не переигрывает финальные статусы
* chore: bundle Paymos PHP SDK v1.5.0
* chore: rebuild canonical CMS package

Fixed:
* When the checkout could not replace an order's invoice (BUG-166), the order
  note read "Paymos payment amount needs manual review." although the block
  also follows a mode or project change, and the buyer read "Paymos payment
  error: unable to create invoice." (BUG-181). The note is now "Paymos payment
  needs manual review." followed by the SDK's summary of the old invoice's
  state, and the buyer reads the SDK's buyer message, "The store needs to
  review this order before payment can continue. Please contact the store."
  Both strings are in the `.pot` and translated in every `.po`/`.mo`.
* A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order is put on hold with a note naming the old
  invoice.
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* The reconciler compared amounts as strings. The snapshot is written with two
  decimals and the server echoes a fiat amount at the currency's own scale, so a
  JPY order (`2500.00` against `2500`) never matched and the fallback for a lost
  webhook skipped it on every run. Amounts are now compared numerically.
* The reconciler re-applied final statuses every ten minutes. Cancelled and
  failed orders were fetched and re-noted on each run — thirty cancellations
  meant thousands of identical notes and API calls a day — and, sharing one
  "newest 50" window with them, an order stuck on hold could fall out of reach.
  Orders whose invoice is already final are no longer fetched, an unchanged
  status writes nothing, waiting orders get their own window, and an invoice
  that was simply not paid yet is no longer described as a rolled-back payment.
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* Paying again for a failed or re-opened order led to a dead invoice. The
  order kept its `external_order_id`, and the server answers a repeated id with
  the same invoice — underpaid, expired or cancelled — so both checkout and
  **Pay invoice** in My Account sent the buyer to a checkout that could no
  longer be paid. Such an order now gets a fresh invoice, **Pay invoice** is
  offered only while the invoice is still open, and the invoice deadline is
  kept on the order.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
* A second invoice could be cut while the first could still be paid. Picking a
  network moves the invoice deadline on the server (`now + PaymentTtl`) and
  sends no webhook, but the order kept the deadline from creation; once that
  passed, paying the order again minted a new `external_order_id` without asking
  the server. The stored id is now always tried first — the server answers it
  with the live invoice — and a new invoice is cut only if the server says the
  old one ended unpaid or was never started. An invoice the server holds open
  (network picked, funds confirming, part paid) is kept, and its status and
  deadline are refreshed on the order. A stored id from another environment or
  project gets a new one, since the server refuses it there.

= 1.3.21 =
* chore: rebuild canonical CMS package

Fixed:
* When the checkout could not replace an order's invoice (BUG-166), the order
  note read "Paymos payment amount needs manual review." although the block
  also follows a mode or project change, and the buyer read "Paymos payment
  error: unable to create invoice." (BUG-181). The note is now "Paymos payment
  needs manual review." followed by the SDK's summary of the old invoice's
  state, and the buyer reads the SDK's buyer message, "The store needs to
  review this order before payment can continue. Please contact the store."
  Both strings are in the `.pot` and translated in every `.po`/`.mo`.
* A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order is put on hold with a note naming the old
  invoice.
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* The reconciler compared amounts as strings. The snapshot is written with two
  decimals and the server echoes a fiat amount at the currency's own scale, so a
  JPY order (`2500.00` against `2500`) never matched and the fallback for a lost
  webhook skipped it on every run. Amounts are now compared numerically.
* The reconciler re-applied final statuses every ten minutes. Cancelled and
  failed orders were fetched and re-noted on each run — thirty cancellations
  meant thousands of identical notes and API calls a day — and, sharing one
  "newest 50" window with them, an order stuck on hold could fall out of reach.
  Orders whose invoice is already final are no longer fetched, an unchanged
  status writes nothing, waiting orders get their own window, and an invoice
  that was simply not paid yet is no longer described as a rolled-back payment.
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* Paying again for a failed or re-opened order led to a dead invoice. The
  order kept its `external_order_id`, and the server answers a repeated id with
  the same invoice — underpaid, expired or cancelled — so both checkout and
  **Pay invoice** in My Account sent the buyer to a checkout that could no
  longer be paid. Such an order now gets a fresh invoice, **Pay invoice** is
  offered only while the invoice is still open, and the invoice deadline is
  kept on the order.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
* A second invoice could be cut while the first could still be paid. Picking a
  network moves the invoice deadline on the server (`now + PaymentTtl`) and
  sends no webhook, but the order kept the deadline from creation; once that
  passed, paying the order again minted a new `external_order_id` without asking
  the server. The stored id is now always tried first — the server answers it
  with the live invoice — and a new invoice is cut only if the server says the
  old one ended unpaid or was never started. An invoice the server holds open
  (network picked, funds confirming, part paid) is kept, and its status and
  deadline are refreshed on the order. A stored id from another environment or
  project gets a new one, since the server refuses it there.

= 1.3.20 =
* chore: rebuild canonical CMS package

Fixed:
* When the checkout could not replace an order's invoice (BUG-166), the order
  note read "Paymos payment amount needs manual review." although the block
  also follows a mode or project change, and the buyer read "Paymos payment
  error: unable to create invoice." (BUG-181). The note is now "Paymos payment
  needs manual review." followed by the SDK's summary of the old invoice's
  state, and the buyer reads the SDK's buyer message, "The store needs to
  review this order before payment can continue. Please contact the store."
  Both strings are in the `.pot` and translated in every `.po`/`.mo`.
* A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order is put on hold with a note naming the old
  invoice.
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* The reconciler compared amounts as strings. The snapshot is written with two
  decimals and the server echoes a fiat amount at the currency's own scale, so a
  JPY order (`2500.00` against `2500`) never matched and the fallback for a lost
  webhook skipped it on every run. Amounts are now compared numerically.
* The reconciler re-applied final statuses every ten minutes. Cancelled and
  failed orders were fetched and re-noted on each run — thirty cancellations
  meant thousands of identical notes and API calls a day — and, sharing one
  "newest 50" window with them, an order stuck on hold could fall out of reach.
  Orders whose invoice is already final are no longer fetched, an unchanged
  status writes nothing, waiting orders get their own window, and an invoice
  that was simply not paid yet is no longer described as a rolled-back payment.
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* Paying again for a failed or re-opened order led to a dead invoice. The
  order kept its `external_order_id`, and the server answers a repeated id with
  the same invoice — underpaid, expired or cancelled — so both checkout and
  **Pay invoice** in My Account sent the buyer to a checkout that could no
  longer be paid. Such an order now gets a fresh invoice, **Pay invoice** is
  offered only while the invoice is still open, and the invoice deadline is
  kept on the order.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
* A second invoice could be cut while the first could still be paid. Picking a
  network moves the invoice deadline on the server (`now + PaymentTtl`) and
  sends no webhook, but the order kept the deadline from creation; once that
  passed, paying the order again minted a new `external_order_id` without asking
  the server. The stored id is now always tried first — the server answers it
  with the live invoice — and a new invoice is cut only if the server says the
  old one ended unpaid or was never started. An invoice the server holds open
  (network picked, funds confirming, part paid) is kept, and its status and
  deadline are refreshed on the order. A stored id from another environment or
  project gets a new one, since the server refuses it there.

= 1.3.19 =
* chore: rebuild canonical CMS package

Fixed:
* When the checkout could not replace an order's invoice (BUG-166), the order
  note read "Paymos payment amount needs manual review." although the block
  also follows a mode or project change, and the buyer read "Paymos payment
  error: unable to create invoice." (BUG-181). The note is now "Paymos payment
  needs manual review." followed by the SDK's summary of the old invoice's
  state, and the buyer reads the SDK's buyer message, "The store needs to
  review this order before payment can continue. Please contact the store."
  Both strings are in the `.pot` and translated in every `.po`/`.mo`.
* A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order is put on hold with a note naming the old
  invoice.
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* The reconciler compared amounts as strings. The snapshot is written with two
  decimals and the server echoes a fiat amount at the currency's own scale, so a
  JPY order (`2500.00` against `2500`) never matched and the fallback for a lost
  webhook skipped it on every run. Amounts are now compared numerically.
* The reconciler re-applied final statuses every ten minutes. Cancelled and
  failed orders were fetched and re-noted on each run — thirty cancellations
  meant thousands of identical notes and API calls a day — and, sharing one
  "newest 50" window with them, an order stuck on hold could fall out of reach.
  Orders whose invoice is already final are no longer fetched, an unchanged
  status writes nothing, waiting orders get their own window, and an invoice
  that was simply not paid yet is no longer described as a rolled-back payment.
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* Paying again for a failed or re-opened order led to a dead invoice. The
  order kept its `external_order_id`, and the server answers a repeated id with
  the same invoice — underpaid, expired or cancelled — so both checkout and
  **Pay invoice** in My Account sent the buyer to a checkout that could no
  longer be paid. Such an order now gets a fresh invoice, **Pay invoice** is
  offered only while the invoice is still open, and the invoice deadline is
  kept on the order.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
* A second invoice could be cut while the first could still be paid. Picking a
  network moves the invoice deadline on the server (`now + PaymentTtl`) and
  sends no webhook, but the order kept the deadline from creation; once that
  passed, paying the order again minted a new `external_order_id` without asking
  the server. The stored id is now always tried first — the server answers it
  with the live invoice — and a new invoice is cut only if the server says the
  old one ended unpaid or was never started. An invoice the server holds open
  (network picked, funds confirming, part paid) is kept, and its status and
  deadline are refreshed on the order. A stored id from another environment or
  project gets a new one, since the server refuses it there.

= 1.3.18 =
* chore: bundle Paymos PHP SDK v1.5.0

Fixed:
* When the checkout could not replace an order's invoice (BUG-166), the order
  note read "Paymos payment amount needs manual review." although the block
  also follows a mode or project change, and the buyer read "Paymos payment
  error: unable to create invoice." (BUG-181). The note is now "Paymos payment
  needs manual review." followed by the SDK's summary of the old invoice's
  state, and the buyer reads the SDK's buyer message, "The store needs to
  review this order before payment can continue. Please contact the store."
  Both strings are in the `.pot` and translated in every `.po`/`.mo`.
* A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order is put on hold with a note naming the old
  invoice.
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* The reconciler compared amounts as strings. The snapshot is written with two
  decimals and the server echoes a fiat amount at the currency's own scale, so a
  JPY order (`2500.00` against `2500`) never matched and the fallback for a lost
  webhook skipped it on every run. Amounts are now compared numerically.
* The reconciler re-applied final statuses every ten minutes. Cancelled and
  failed orders were fetched and re-noted on each run — thirty cancellations
  meant thousands of identical notes and API calls a day — and, sharing one
  "newest 50" window with them, an order stuck on hold could fall out of reach.
  Orders whose invoice is already final are no longer fetched, an unchanged
  status writes nothing, waiting orders get their own window, and an invoice
  that was simply not paid yet is no longer described as a rolled-back payment.
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* Paying again for a failed or re-opened order led to a dead invoice. The
  order kept its `external_order_id`, and the server answers a repeated id with
  the same invoice — underpaid, expired or cancelled — so both checkout and
  **Pay invoice** in My Account sent the buyer to a checkout that could no
  longer be paid. Such an order now gets a fresh invoice, **Pay invoice** is
  offered only while the invoice is still open, and the invoice deadline is
  kept on the order.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
* A second invoice could be cut while the first could still be paid. Picking a
  network moves the invoice deadline on the server (`now + PaymentTtl`) and
  sends no webhook, but the order kept the deadline from creation; once that
  passed, paying the order again minted a new `external_order_id` without asking
  the server. The stored id is now always tried first — the server answers it
  with the live invoice — and a new invoice is cut only if the server says the
  old one ended unpaid or was never started. An invoice the server holds open
  (network picked, funds confirming, part paid) is kept, and its status and
  deadline are refreshed on the order. A stored id from another environment or
  project gets a new one, since the server refuses it there.

= 1.3.17 =
* chore: bundle Paymos PHP SDK v1.4.4

Fixed:
* When the checkout could not replace an order's invoice (BUG-166), the order
  note read "Paymos payment amount needs manual review." although the block
  also follows a mode or project change, and the buyer read "Paymos payment
  error: unable to create invoice." (BUG-181). The note is now "Paymos payment
  needs manual review." followed by the SDK's summary of the old invoice's
  state, and the buyer reads the SDK's buyer message, "The store needs to
  review this order before payment can continue. Please contact the store."
  Both strings are in the `.pot` and translated in every `.po`/`.mo`.
* A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order is put on hold with a note naming the old
  invoice.
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* The reconciler compared amounts as strings. The snapshot is written with two
  decimals and the server echoes a fiat amount at the currency's own scale, so a
  JPY order (`2500.00` against `2500`) never matched and the fallback for a lost
  webhook skipped it on every run. Amounts are now compared numerically.
* The reconciler re-applied final statuses every ten minutes. Cancelled and
  failed orders were fetched and re-noted on each run — thirty cancellations
  meant thousands of identical notes and API calls a day — and, sharing one
  "newest 50" window with them, an order stuck on hold could fall out of reach.
  Orders whose invoice is already final are no longer fetched, an unchanged
  status writes nothing, waiting orders get their own window, and an invoice
  that was simply not paid yet is no longer described as a rolled-back payment.
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* Paying again for a failed or re-opened order led to a dead invoice. The
  order kept its `external_order_id`, and the server answers a repeated id with
  the same invoice — underpaid, expired or cancelled — so both checkout and
  **Pay invoice** in My Account sent the buyer to a checkout that could no
  longer be paid. Such an order now gets a fresh invoice, **Pay invoice** is
  offered only while the invoice is still open, and the invoice deadline is
  kept on the order.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
* A second invoice could be cut while the first could still be paid. Picking a
  network moves the invoice deadline on the server (`now + PaymentTtl`) and
  sends no webhook, but the order kept the deadline from creation; once that
  passed, paying the order again minted a new `external_order_id` without asking
  the server. The stored id is now always tried first — the server answers it
  with the live invoice — and a new invoice is cut only if the server says the
  old one ended unpaid or was never started. An invoice the server holds open
  (network picked, funds confirming, part paid) is kept, and its status and
  deadline are refreshed on the order. A stored id from another environment or
  project gets a new one, since the server refuses it there.

= 1.3.16 =
* docs(plugins): переводы сообщения о заблокированной замене счёта и сверка минимальных версий
* fix(plugins): BUG-181 WooCommerce и CS-Cart называют настоящую причину и переводят текст покупателю; минимум PHP для OpenCart — 8.0
* chore: rebuild canonical CMS package

Fixed:
* When the checkout could not replace an order's invoice (BUG-166), the order
  note read "Paymos payment amount needs manual review." although the block
  also follows a mode or project change, and the buyer read "Paymos payment
  error: unable to create invoice." (BUG-181). The note is now "Paymos payment
  needs manual review." followed by the SDK's summary of the old invoice's
  state, and the buyer reads the SDK's buyer message, "The store needs to
  review this order before payment can continue. Please contact the store."
  Both strings are in the `.pot` and translated in every `.po`/`.mo`.
* A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order is put on hold with a note naming the old
  invoice.
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* The reconciler compared amounts as strings. The snapshot is written with two
  decimals and the server echoes a fiat amount at the currency's own scale, so a
  JPY order (`2500.00` against `2500`) never matched and the fallback for a lost
  webhook skipped it on every run. Amounts are now compared numerically.
* The reconciler re-applied final statuses every ten minutes. Cancelled and
  failed orders were fetched and re-noted on each run — thirty cancellations
  meant thousands of identical notes and API calls a day — and, sharing one
  "newest 50" window with them, an order stuck on hold could fall out of reach.
  Orders whose invoice is already final are no longer fetched, an unchanged
  status writes nothing, waiting orders get their own window, and an invoice
  that was simply not paid yet is no longer described as a rolled-back payment.
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* Paying again for a failed or re-opened order led to a dead invoice. The
  order kept its `external_order_id`, and the server answers a repeated id with
  the same invoice — underpaid, expired or cancelled — so both checkout and
  **Pay invoice** in My Account sent the buyer to a checkout that could no
  longer be paid. Such an order now gets a fresh invoice, **Pay invoice** is
  offered only while the invoice is still open, and the invoice deadline is
  kept on the order.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
* A second invoice could be cut while the first could still be paid. Picking a
  network moves the invoice deadline on the server (`now + PaymentTtl`) and
  sends no webhook, but the order kept the deadline from creation; once that
  passed, paying the order again minted a new `external_order_id` without asking
  the server. The stored id is now always tried first — the server answers it
  with the live invoice — and a new invoice is cut only if the server says the
  old one ended unpaid or was never started. An invoice the server holds open
  (network picked, funds confirming, part paid) is kept, and its status and
  deadline are refreshed on the order. A stored id from another environment or
  project gets a new one, since the server refuses it there.

