# Changelog

All notable changes to the Paymos for WooCommerce plugin are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

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
