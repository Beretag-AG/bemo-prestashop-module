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
| Scheduler | PrestaShop Cron tasks manager (`cronjobs`) **or** any scheduler that can call a URL |

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
- Drains the queue from the Cron tasks manager module or from a
  token-authenticated URL, so a stock PrestaShop install needs no extra module.
- Validates short-lived signed purchase links, accepts each one only once, and
  resolves their product only inside the currently selected shop.
- Revokes the read-only Webservice key and clears stored credentials when the
  merchant turns catalog access off or disconnects the shop.
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
5. Review the setup choices: read-only catalog access, whether viewers may buy
   without leaving the show, and whether a product click opens the cart or
   continues directly to checkout.
6. Click **Save and connect to BEMO**, then claim the shop from the BEMO
   account that should sell its products. Until it is claimed, the page offers
   **Restart connection** to request a fresh claim link.

The configuration page follows the connection: a first-run welcome and the
setup choices before connecting, a claim status while BEMO has not claimed the
shop yet, and the connection, catalog sync, settings, and disconnect panels once
it has. The BEMO endpoints are editable only when PrestaShop developer mode is
enabled; otherwise the production endpoints are used and hidden.

Queued catalog events need a scheduler. Either keep the **Cron tasks manager**
module active, or call the sync address shown in the **Catalog sync** panel from
the hosting platform's scheduler, for example every five minutes:

```cron
*/5 * * * * curl -sS -o /dev/null 'https://shop.example/module/bemoliveshopping/cron?token=...'
```

The sync address carries a per-shop token and does nothing else. Treat it like a
credential: anyone holding it can trigger delivery of already-queued events.

To stop the integration, open **BEMO Live Shopping → Configure** and use the
**Disconnect from BEMO** panel at the bottom of the page. That deletes the module-created Webservice key and
clears the stored credentials for the current shop.

## Embedded checkout

BEMO can keep the PrestaShop cart and checkout inside the live session, but the
shop must explicitly request a cross-site iframe and pass BEMO's staging review.
Otherwise the shop's configured cart or checkout landing opens in a new tab.

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
provider remains the checkout and payment processor. Payment details are
submitted directly to the shop or its payment provider; BEMO's servers do not
process or store those details in this flow.

### Merchant handoff checklist

Ask the merchant or hosting provider to complete these steps on a staging copy
before BEMO enables embedded checkout on the live shop:

1. Install or upgrade **BEMO Live Shopping 0.5.0 or newer** and schedule the
   catalog sync, either with **Cron tasks manager** or with the sync address.
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
   settings are live. Select **Yes** for **Show checkout inside BEMO** only
   after the header and cookie checks pass. BEMO enables it only after the
   staging review is recorded by an admin.
6. From a legitimate viewer account, test one highlighted product and finish
   a sandbox order in the BEMO dialog and with **Open in new tab**, for every
   enabled payment method and 3-D Secure flow.

For production use `https://bemo.now`; for BEMO staging use
`https://beta.bemo.now`. If any part of the merchant's checkout stack cannot be
framed, keep the connection in `link_out` mode. The normal top-level checkout
remains the supported fallback.

### Merchant responsibilities

The merchant remains responsible for payment-provider configuration, taxes,
shipping, fulfillment, refunds, customer terms and privacy notices, and the
legal or regulatory obligations of the storefront. Review checkout terms and
seek appropriate legal advice before enabling a live shop.

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

- PrestaShop Webservice access is limited to `GET` and `HEAD` on required
  catalog resources.
- Customer and order data are not exposed through the Webservice account.
- Pairing, webhook, and purchase-link secrets are generated independently.
- Pairing, webhook, and purchase-link secrets are never rendered back into Back
  Office pages or written to logs. The event drain token is the one exception:
  it is shown on the configuration page because a merchant has to paste it into
  a scheduler, and it authorizes only queue delivery.
- Signed purchase links are accepted once per shop; a replayed link fails like
  any other invalid link.
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
