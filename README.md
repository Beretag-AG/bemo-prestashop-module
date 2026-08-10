# BEMO Live Shopping for PrestaShop

[![CI](https://github.com/Beretag-AG/bemo-prestashop-module/actions/workflows/ci.yml/badge.svg)](https://github.com/Beretag-AG/bemo-prestashop-module/actions/workflows/ci.yml)

The official open-source PrestaShop integration for [BEMO](https://bemo.now).
It connects a merchant's catalog to their BEMO creator account so products can
be presented during paid live sessions while checkout remains on the
merchant's own storefront.

> [!IMPORTANT]
> This module is under active development and is being prepared for a
> production pilot. Pairing, durable catalog events, and signed purchase links
> are implemented; complete the staging validation checklist before installing
> it on a live shop.

## Requirements

| Component | Supported versions |
| --- | --- |
| PrestaShop | 1.7.6 through 8.x |
| PHP | 7.2.5 through 8.1 |
| Module dependency | PrestaShop Cron tasks manager (`cronjobs`) |

PrestaShop 1.7.6 installations running PHP 5.6–7.1 must upgrade PHP before
installing this module. PrestaShop 9 is not supported by the current release.

## Current capabilities

- Installs and removes its own module data without changing PrestaShop core
  tables.
- Provides a native Back Office configuration page.
- Enables PrestaShop Webservice access only after explicit administrator
  confirmation.
- Creates a shop-scoped, read-only Webservice account with only the catalog
  permissions BEMO needs.
- Generates independent secrets for account pairing, outbound events, and
  signed purchase links.
- Starts a short-lived BEMO pairing handoff and redirects the administrator to
  the configured BEMO application without rendering or logging credentials.
- Queues catalog-change events durably and delivers exact-byte HMAC-signed
  webhooks outside merchant requests, with idempotent ingestion and retry.
- Validates short-lived signed purchase links and resolves their product only
  inside the currently selected shop.
- Removes only module-owned Webservice accounts during uninstall, including in
  multistore installations.
- Produces a deterministic ZIP that can be uploaded through Module Manager.

## Installation

There is no general-availability release yet. To create a pilot build:

```bash
git clone https://github.com/Beretag-AG/bemo-prestashop-module.git
cd bemo-prestashop-module
composer install
composer package
```

This creates `dist/bemoliveshopping.zip`.

To install it in a development or staging shop:

1. Open **Modules → Module Manager** in PrestaShop Back Office.
2. Select **Upload a module**.
3. Upload `bemoliveshopping.zip`.
4. Open **BEMO Live Shopping → Configure**.
5. Confirm the production BEMO endpoints. Custom endpoints are available only
   when PrestaShop developer mode is enabled.
6. Review the Webservice notice and explicitly activate the BEMO account.

Keep the Cron tasks manager active so queued catalog events are drained. Use
its Advanced mode when the hosting platform already provides a scheduler.

## Embedded checkout

BEMO can keep PrestaShop checkout inside the live session, but the shop must
explicitly be ready for a cross-site iframe. Otherwise BEMO safely opens the
canonical product page in a new tab.

Before pairing a shop for embedded checkout:

1. Serve the complete storefront and checkout over HTTPS.
2. In **Advanced Parameters → Administration**, set **Cookie SameSite** to
   **None**. The exact label can vary slightly by PrestaShop version.
3. Remove `X-Frame-Options: SAMEORIGIN` or `DENY` from checkout responses.
4. If the shop sends Content Security Policy, allow the relevant BEMO app
   origins in `frame-ancestors`, for example `https://bemo.now` and
   `https://beta.bemo.now` during staging.
5. Pair the shop again after changing these settings. Readiness is captured at
   pairing time; BEMO does not weaken shop headers or cookie policy remotely.
6. Complete a real sandbox order for every enabled payment method in desktop
   Chrome and mobile Safari. A payment provider, 3-D Secure challenge, CDN, or
   browser privacy policy can still require top-level navigation, so BEMO
   always provides **Open in new tab** as a fallback.

Quickly inspect the public response headers (replace the URL with the real
checkout URL):

```bash
curl -sSI https://shop.example/checkout \
  | grep -Ei 'x-frame-options|content-security-policy|set-cookie'
```

There must be no blocking `X-Frame-Options`; any `frame-ancestors` directive
must include the BEMO origin; and the PrestaShop session and cart cookies must
be `Secure` with `SameSite=None; Partitioned`. PrestaShop 8 does not emit the
`Partitioned` attribute itself on PHP 8.1, so configure it at the host, reverse
proxy, or CDN for secure storefront cookies. The merchant's PrestaShop/payment
provider remains the checkout and payment processor. BEMO never receives card
details.

### Merchant handoff checklist

Ask the merchant or hosting provider to complete these steps on a staging copy
before BEMO enables embedded checkout on the live shop:

1. Install or upgrade **BEMO Live Shopping 0.3.3 or newer** and keep **Cron
   tasks manager** active.
2. Enable HTTPS for the entire storefront, including cart, checkout, payment,
   return, and confirmation pages.
3. Set **Cookie SameSite** to **None** under **Advanced Parameters →
   Administration**. At the host, reverse proxy, or CDN, append `Partitioned`
   to the secure PrestaShop session and cart cookies. Confirm the resulting
   cookies are marked `Secure; SameSite=None; Partitioned`.
4. Ask the host, CDN, theme, and checkout/payment-module owners to allow the
   exact BEMO origin in every framed response. Remove blocking
   `X-Frame-Options`; when CSP is used, include the BEMO origin in
   `frame-ancestors`.
5. Re-pair the shop from the BEMO module configuration page after those
   settings are live. Select **Yes** for **Allow checkout inside BEMO** only
   after the header and cookie checks pass. BEMO records readiness only during
   pairing.
6. From a legitimate viewer account, test one highlighted product and finish
   a sandbox order in the BEMO dialog and with **Open in new tab**, for every
   enabled payment method and 3-D Secure flow.

For production use `https://bemo.now`; for BEMO staging use
`https://beta.bemo.now`. If any part of the merchant's checkout stack cannot be
framed, keep the connection in `link_out` mode. The normal top-level checkout
remains the supported fallback.

## Development

The repository uses current PHP and Composer for dependency management while
testing the two supported runtime edges independently.

### macOS toolchain

```bash
brew tap shivammathur/php
brew install php php@8.1 shivammathur/php/php@7.2 composer
brew unlink php@7.2
brew link php
```

This leaves the current stable PHP as the default and keeps PHP 7.2 and 8.1
available as keg-only compatibility runtimes.

### Install and verify

```bash
composer install
composer verify
```

`composer verify` runs:

- syntax checks on PHP 7.2 and PHP 8.1;
- the unit suite on both runtimes;
- validation of the installable ZIP structure.

Composer resolves dependencies against PHP 7.2.5 so the lockfile cannot
silently select packages that require a newer module runtime.

### Worktrees

After creating a Git worktree, initialize it from inside the new checkout:

```bash
scripts/init-worktree
composer install
```

The initializer copies optional `.env` and `.env.local` files from the primary
checkout, skips files that already exist, and does nothing in the primary
checkout. Use `scripts/init-worktree --force` only when you intentionally want
to replace existing local files.

## Security

- PrestaShop Webservice access is limited to `GET` on required
  catalog resources.
- Customer and order data are not exposed through the Webservice account.
- Pairing, webhook, and purchase-link secrets are generated independently.
- Secrets are never rendered back into Back Office pages or written to logs.
- Production credentials can be sent only to `https://actions.bemo.now` and
  redirect only to `https://bemo.now`. Developer-mode overrides still require
  HTTPS, except for explicit localhost URLs.
- Uninstall never disables the shop-wide Webservice setting because another
  integration may depend on it.

Please report security concerns privately to the repository maintainers rather
than opening a public issue with sensitive details.

## License

Licensed under the [Academic Free License 3.0](LICENSE.md).

Copyright © 2026 Beretag AG.
