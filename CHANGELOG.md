# Changelog

All notable changes to the Paymos for WooCommerce plugin are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [Unreleased]

## [1.3.15] - 2026-09-25

- fix(plugins): BUG-166 старый счёт закрывается на сервере до выпуска нового; открытый, оплаченный или 404 — в ручную проверку
- chore: bundle Paymos PHP SDK v1.4.3
- chore: rebuild canonical CMS package

### Fixed
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order is put on hold with a note naming the old
  invoice.
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
- The reconciler compared amounts as strings. The snapshot is written with two
  decimals and the server echoes a fiat amount at the currency's own scale, so a
  JPY order (`2500.00` against `2500`) never matched and the fallback for a lost
  webhook skipped it on every run. Amounts are now compared numerically.
- The reconciler re-applied final statuses every ten minutes. Cancelled and
  failed orders were fetched and re-noted on each run — thirty cancellations
  meant thousands of identical notes and API calls a day — and, sharing one
  "newest 50" window with them, an order stuck on hold could fall out of reach.
  Orders whose invoice is already final are no longer fetched, an unchanged
  status writes nothing, waiting orders get their own window, and an invoice
  that was simply not paid yet is no longer described as a rolled-back payment.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- Paying again for a failed or re-opened order led to a dead invoice. The
  order kept its `external_order_id`, and the server answers a repeated id with
  the same invoice — underpaid, expired or cancelled — so both checkout and
  **Pay invoice** in My Account sent the buyer to a checkout that could no
  longer be paid. Such an order now gets a fresh invoice, **Pay invoice** is
  offered only while the invoice is still open, and the invoice deadline is
  kept on the order.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- A second invoice could be cut while the first could still be paid. Picking a
  network moves the invoice deadline on the server (`now + PaymentTtl`) and
  sends no webhook, but the order kept the deadline from creation; once that
  passed, paying the order again minted a new `external_order_id` without asking
  the server. The stored id is now always tried first — the server answers it
  with the live invoice — and a new invoice is cut only if the server says the
  old one ended unpaid or was never started. An invoice the server holds open
  (network picked, funds confirming, part paid) is kept, and its status and
  deadline are refreshed on the order. A stored id from another environment or
  project gets a new one, since the server refuses it there.

## [1.3.14] - 2026-09-25

- fix(woocommerce): BUG-163 второй счёт не выпускается, пока сервер держит первый открытым
- fix(plugins): BUG-103 вебхук, который ещё обрабатывается, больше не отвечается 200 «duplicate»
- fix(plugins): BUG-090 оплата больше не ведёт на истёкший или проваленный счёт Paymos
- fix(plugins): BUG-135 поздний нефинальный вебхук больше не оживляет проваленный или отменённый заказ
- fix(plugins): BUG-133 BUG-134 реконсилятор WooCommerce сверяет суммы численно и не переигрывает финальные статусы
- chore: bundle Paymos PHP SDK v1.4.2

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
- The reconciler compared amounts as strings. The snapshot is written with two
  decimals and the server echoes a fiat amount at the currency's own scale, so a
  JPY order (`2500.00` against `2500`) never matched and the fallback for a lost
  webhook skipped it on every run. Amounts are now compared numerically.
- The reconciler re-applied final statuses every ten minutes. Cancelled and
  failed orders were fetched and re-noted on each run — thirty cancellations
  meant thousands of identical notes and API calls a day — and, sharing one
  "newest 50" window with them, an order stuck on hold could fall out of reach.
  Orders whose invoice is already final are no longer fetched, an unchanged
  status writes nothing, waiting orders get their own window, and an invoice
  that was simply not paid yet is no longer described as a rolled-back payment.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- Paying again for a failed or re-opened order led to a dead invoice. The
  order kept its `external_order_id`, and the server answers a repeated id with
  the same invoice — underpaid, expired or cancelled — so both checkout and
  **Pay invoice** in My Account sent the buyer to a checkout that could no
  longer be paid. Such an order now gets a fresh invoice, **Pay invoice** is
  offered only while the invoice is still open, and the invoice deadline is
  kept on the order.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- A second invoice could be cut while the first could still be paid. Picking a
  network moves the invoice deadline on the server (`now + PaymentTtl`) and
  sends no webhook, but the order kept the deadline from creation; once that
  passed, paying the order again minted a new `external_order_id` without asking
  the server. The stored id is now always tried first — the server answers it
  with the live invoice — and a new invoice is cut only if the server says the
  old one ended unpaid or was never started. An invoice the server holds open
  (network picked, funds confirming, part paid) is kept, and its status and
  deadline are refreshed on the order. A stored id from another environment or
  project gets a new one, since the server refuses it there.

## [1.3.13] - 2026-09-23

- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).

## [1.3.12] - 2026-09-21

- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).

## [1.3.11] - 2026-09-15

- chore: bundle Paymos PHP SDK v1.4.1

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).

## [1.3.10] - 2026-08-30

- test(plugins): сьюта WooCommerce впервые прогнана на 7.4 и 8.0 — и не проходила там
- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).

## [1.3.9] - 2026-08-30

- fix(plugins): CMS marketplace readiness spec, phases 1-3
- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).

## [1.3.8] - 2026-08-30

- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish, the plugin name and
  `Webhook URL` in Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).

## [1.3.7] - 2026-08-28

- release: the changelog rot had a cause, and it was not the one I named
- audit: the shipped plugin and SDK docs described a product we stopped shipping
- docs(plugins): eight README stubs become the front pages they already were
- fix(woocommerce): the plugin is not in the WordPress directory
- fix(i18n): unblock the production build — the gate was right, the map was stale
- docs(plugins): the changelogs stopped in June and the audit never reached them
- chore: bundle Paymos PHP SDK v1.4.0
- chore: rebuild canonical CMS package

## [1.3.6] - 2026-08-08

- fix(plugins): make the six shipped locales actually reach the merchant
- chore: rebuild canonical CMS package

## [1.3.5] - 2026-08-08

- chore: bundle Paymos PHP SDK v1.3.2

## [1.3.4] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.3.3] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.3.2] - 2026-08-07

- fix(plugins): tell the merchant an invoice was underpaid, not confirming

## [1.3.1] - 2026-08-07

- fix(cms-connect): open the approval tab and return the merchant to the store
- chore: bundle Paymos PHP SDK v1.3.1

## [1.3.0] - 2026-08-06

- feat(locales): Spanish blog and plugin catalogs
- feat(locales): German blog corpus, plugin catalogs and bot text
- feat(locales): tr + zh-Hans platform rollout — resx, bots, plugins
- chore: bundle Paymos PHP SDK v1.3.0

## [1.2.0] - 2026-08-03

- feat: finalize CMS integration and localization tooling
- Merge remote-tracking branch 'origin/main'
- feat: consolidate BotexV2, Rentron, and ecosystem updates
- chore: bundle Paymos PHP SDK v1.3.0
- chore: rebuild canonical CMS package

## [1.1.2] - 2026-08-02

- chore: rebuild canonical CMS package

## [1.1.1] - 2026-08-02

- fix(ecosystem): recover SDK releases
- chore: bundle Paymos PHP SDK v1.2.1
- chore: rebuild canonical CMS package

## [1.1.0] - 2026-07-21

- feat(docs): make the developer surface consumable by LLM agents
- chore: bundle Paymos PHP SDK v1.2.0
- chore: rebuild canonical CMS package

## [1.0.7] - 2026-07-19

- chore: bundle Paymos PHP SDK v1.1.1

## [1.0.6] - 2026-07-13

- chore: rebuild canonical CMS package

## [1.0.5] - 2026-07-12

- fix(plugins): align CMS guidance with secure Connect

## [1.0.4] - 2026-07-12

- chore: rebuild canonical CMS package

## [1.0.3] - 2026-07-12

- chore: rebuild canonical CMS package

## [1.0.2] - 2026-07-12

- fix(release): align package stamping and webhook fixtures
- chore: rebuild canonical CMS package

## [1.0.1] - 2026-06-22

### Added
- Russian localization: `languages/` with `.pot` + `ru_RU` `.po`/`.mo` (59 strings).
- Localization is now bundled in the dashboard ZIP (was previously omitted from the build).

### Changed
- `load_plugin_textdomain` moved to the `init` hook for WordPress 6.7+ compatibility; JS block strings fall back through `wp.i18n.__`.

### Fixed
- README no longer advertises a non-existent "Status mappings" setting or an admin "force-check" action (neither exists in the code).
- Removed DAI from checkout copy across the gateway, Blocks, and JS — DAI is Ethereum-only and was misrepresented as broadly available.

## [1.0.0] - 2026-05-30

### Added
- Initial release.
- USDT and USDC payments across 13 mainnet networks.
- Hosted Paymos checkout page launched from WooCommerce checkout.
- Classic Checkout and Checkout Blocks support.
- HPOS (High-Performance Order Storage) compatible.
- Pre-registered webhook endpoint with HMAC-SHA256 signature verification.
- 10-minute reconciler that polls unresolved invoices.
- Sandbox / Live mode switch in the WooCommerce admin.
- API credentials and signing secret pre-injected by the dashboard ZIP generator.
