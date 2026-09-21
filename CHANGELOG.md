# Changelog

All notable changes to the Paymos for WooCommerce plugin are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [Unreleased]

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
