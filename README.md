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
| PrestaShop | 1.7.3.1 through 8.x |
| PHP | 7.0 through 8.1 |
| Catalog sync | Managed by BEMO; no PrestaShop cron module is required |

BEMO checks connected shops from its own servers every 15 minutes, and every
minute while a creator is live or preparing a session. The module also queues
catalog-change notifications. A private retry URL lets the host deliver them
without making outbound calls during storefront or Back Office requests.

PrestaShop 1.7.3 installations running PHP 5.4–5.6 must upgrade PHP before
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
- Queues catalog-change events durably. A token-authenticated retry URL sends
  exact-byte HMAC-signed notifications outside storefront and Back Office
  requests; BEMO handles repeated deliveries idempotently.
- Validates short-lived signed purchase links, accepts each one only once, and
  validates every requested cart line inside the currently selected shop.
- Revokes the read-only Webservice key and clears stored credentials when the
  merchant turns catalog access off or disconnects the shop.
- Removes only module-owned Webservice accounts during uninstall, including in
  multistore installations.
- Produces a deterministic ZIP that can be uploaded through Module Manager.

## Installation

Download the ZIP for the environment you are testing from the matching GitHub
release:

- `bemoliveshopping-<version>-staging.zip` connects only to
  `https://beta.bemo.now` and its staging API. Send this file to pilot shops.
- `bemoliveshopping-<version>.zip` connects only to production BEMO.

To build the same files locally:

```bash
git clone https://github.com/Beretag-AG/bemo-prestashop-module.git
cd bemo-prestashop-module
composer install
composer package
bash scripts/package.sh staging
```

Never rename the staging archive to remove `-staging`; the filename is an
intentional warning about which BEMO environment receives the shop credentials.

To install it in a development or staging shop:

1. Open **Modules → Module Manager** in PrestaShop Back Office.
2. Select **Upload a module**.
3. Upload the production or `-staging` ZIP selected above.
4. Open **BEMO Live Shopping → Configure**.
5. Review the setup choices: read-only catalog access and whether viewers may
   buy without leaving the show. Signed BEMO carts always open the native cart.
6. Click **Save and connect to BEMO**, then claim the shop from the BEMO
   account that should sell its products. Until it is claimed, the page offers
   **Restart connection** to request a fresh claim link.

If an update upload stalls or Module Manager reports an error after extracting
the ZIP, let that request finish, then open **BEMO Live Shopping → Configure**
from its action menu. Starting with 0.8.6, **Finish BEMO update** can recover
updates from 0.8.2 or newer without scanning other modules. It runs BEMO's own
pending upgrade scripts and keeps existing connections and settings. A failed
step can be retried; the module never marks an unfinished migration complete.
This recovers BEMO after a failed upload. It does not repair errors in other
modules or PrestaShop's general uploader. Do not uninstall or reset BEMO to
recover an update, as that removes its settings and catalog credentials.

For a multishop installation, install or update the module once, then open its
configuration page. The first page lists every shop and its BEMO connection
status. Open and connect each shop that should appear in BEMO. Each connection
has its own catalog key, webhook secret, purchase-link secret, endpoints, and
checkout choice.

A BEMO live session selects one connected shop. BEMO reads products, prices,
languages, and currencies only in that shop's context. When the shop group
shares inventory, BEMO follows PrestaShop's group stock scope while keeping the
selected shop as the catalog and checkout context.

Shared-inventory support requires module 0.8.4 or newer. If an older BEMO
connection does not show a shop ID, connect that shop again after updating.
Previously disconnected products without a saved shop identity must be imported
again so BEMO can assign them to the correct shop. Changes to shared inventory
may reach sibling shops on their next scheduled catalog refresh.

The configuration page follows the connection: a first-run welcome and the
setup choices before connecting, a claim status while BEMO has not claimed the
shop yet, and the connection, catalog sync, settings, and disconnect panels once
it has. Production and staging archives lock their BEMO endpoints and hide them.
Arbitrary endpoints are editable only when PrestaShop developer mode is enabled.

No local scheduler is required. BEMO reads the connected shop on its own
schedule. Hosts may optionally call the private retry address shown in
the **Catalog sync** panel every five minutes:

```cron
*/5 * * * * curl -sS -o /dev/null 'https://shop.example/module/bemoliveshopping/cron?token=...'
```

The sync address carries a per-shop token and does nothing else. Treat it like a
credential: anyone holding it can trigger delivery of already-queued events.

To stop the integration, open **BEMO Live Shopping → Configure** and use the
**Disconnect from BEMO** panel at the bottom of the page. That deletes the module-created Webservice key and
clears the stored credentials for the current shop.

### Reconnecting after a BEMO disconnect

In the module settings, choose the affected shop and select **Reconnect to BEMO**.
Sign in to the BEMO account that should own the shop. You do not need to disconnect
or delete the catalog key first. **Setup completed** records a previous successful
setup; check BEMO for the current connection and catalog sync status.


## Embedded checkout

BEMO can keep the PrestaShop cart and checkout inside the live session. The
merchant opts in with **Show checkout inside BEMO** in the module settings.
BEMO then checks the shop automatically and turns embedded checkout on only
when the check passes. Otherwise the shop's native cart opens in a new tab.

From 0.9.0 the module sends the required headers itself, so most shops need no
server changes:

- On storefront pages it sends
  `Content-Security-Policy: frame-ancestors 'self' <BEMO app origin>`.
  Browsers ignore `X-Frame-Options` when `frame-ancestors` is present, so an
  existing `SAMEORIGIN` header can stay and every other site stays blocked.
  The origin comes from the archive's environment: the production archive
  admits only `https://bemo.now`, the staging archive only
  `https://beta.bemo.now`. PrestaShop developer mode uses the custom app URL
  it paired with.
- Only for requests the browser marks as a cross-site frame
  (`Sec-Fetch-Dest: iframe`, `Sec-Fetch-Site: cross-site`) over HTTPS, it
  sends the PrestaShop session cookie with `Secure; SameSite=None;
  Partitioned`. That cookie lives in its own browser partition, so normal
  visits to the shop keep their existing cookie.

Both only apply after the merchant opts in. BEMO re-checks the shop at
pairing, after opt-in, daily, and whenever the creator presses **Sync now**.
When something still blocks it, BEMO's shop settings show the reason in plain
language plus a copyable brief for the merchant's developer or hosting
support.

What can still block embedded checkout, and needs the host or developer:

1. The storefront or checkout is not fully served over HTTPS.
2. A web server, CDN, or security module sends its own
   `Content-Security-Policy` whose `frame-ancestors` excludes the BEMO origin.
   Every CSP header is enforced, so that policy must include the BEMO origin
   too.
3. Something rewrites or strips the module's `Set-Cookie` changes after
   PrestaShop sends them.
4. A payment provider, 3-D Secure challenge, or browser privacy policy needs
   top-level navigation. BEMO always offers **Open in new tab** for this.

Inspect the headers BEMO sees (replace the URL with the real cart URL):

```bash
curl -sSI \
  -H 'Sec-Fetch-Dest: iframe' \
  -H 'Sec-Fetch-Mode: navigate' \
  -H 'Sec-Fetch-Site: cross-site' \
  'https://shop.example/index.php?controller=cart&action=show' \
  | grep -Ei 'x-frame-options|content-security-policy|set-cookie'
```

Every `frame-ancestors` directive must include the BEMO origin, and the
PrestaShop session cookie must be `Secure; SameSite=None; Partitioned`. The
merchant's PrestaShop and payment provider remain the checkout and payment
processor. Payment details go directly to the shop or its payment provider;
BEMO's servers do not process or store them in this flow.

### Merchant checklist

1. Install or upgrade **BEMO Live Shopping 0.9.0 or newer**. Use the archive
   whose filename matches the BEMO environment. No PrestaShop cron module is
   required.
2. Serve the entire storefront over HTTPS, including cart, checkout, payment,
   return, and confirmation pages.
3. Select **Yes** for **Show checkout inside BEMO** in the module settings.
4. Open BEMO **Settings → Integrations**. If embedded checkout is not
   available, follow the listed fix or send the developer details to the host.
5. From a legitimate viewer account, test one highlighted product and finish
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

### Data exchanged with BEMO

The pairing request sends BEMO these fields:

- shop ID, name, canonical URL, and PrestaShop version;
- default language ID, enabled language codes, and enabled currency codes;
- whether the shop's current settings are ready for embedded checkout;
- a short-lived pairing token;
- a read-only Webservice key; and
- independent webhook-signing and purchase-link-signing secrets.

While pairing is pending, status checks send only the short-lived pairing
token. After pairing, the Webservice key authorizes `GET` and `HEAD` for
products, categories, variants, variant option values, and stock. It has no
access to PrestaShop's customer or order resources. BEMO also requests
canonical product IDs and URLs, the embedded-checkout setting, and the module
version through an authenticated module route.

An authenticated voucher route returns at most 100 general shop voucher codes.
It filters out customer-bound rules inside PrestaShop before returning the
voucher ID, code, quantity, validity dates, and active state. The Webservice key
does not authorize the broader `cart_rules` resource.

Product, stock, price, voucher, and configuration changes create signed
notifications. Every notification contains an event ID, event type, event time,
shop ID and URL, resource type, and resource ID. Configuration notifications
also contain the requested embedded-checkout setting and, when available, the
module version.

BEMO sends the module signed, short-lived purchase links. Current links contain
a version, BEMO cart, connection and session IDs, issue and expiry times, a
single-use nonce, and product or variant IDs with quantities. Legacy links can
contain one BEMO product ID and one external product ID instead. These links do
not contain customer identity, address, checkout-form, or payment data.

The module does not send checkout form fields, payment details, customer
accounts, addresses, or completed order records to BEMO. The merchant should
describe the catalog integration and BEMO as a recipient where its own privacy
information requires that disclosure.

### Data stored by the module

The module stores its Webservice key, webhook and purchase-link secrets,
short-lived pairing token and timestamps, and private retry token in the shop's
PrestaShop database. PrestaShop stores these values as plain database columns,
so the merchant or host must protect database access and backups.

Queued notifications remain in the module database until successful delivery.
Permanently failed notifications remain for up to 30 days, and pending events
may remain until delivery or queue-limit cleanup. For purchase-link replay
protection, the module stores the shop ID, nonce, expiry time, and creation time
until an ordinary cleanup run removes the expired entry.

Disconnecting clears the pairing credentials and deletes the module-created
Webservice account. Uninstalling deletes all module tables and removes every
module-owned Webservice account.

## Development

The repository uses current PHP and Composer for dependency management while
testing the two supported runtime edges independently.

### macOS toolchain

```bash
brew tap shivammathur/php
brew install php php@8.1 shivammathur/php/php@7.0 shivammathur/php/php@7.2 composer
brew unlink php@7.0
brew link php
```

This leaves the current stable PHP as the default and keeps PHP 7.0, 7.2, and
8.1 available as keg-only compatibility runtimes.

### Install and verify

```bash
composer install
composer verify
```

`composer verify` runs:

- syntax checks on PHP 7.0 and PHP 8.1;
- the unit suite on both runtimes;
- validation of the installable ZIP structure.

The module has no production Composer packages. Composer resolves development
dependencies against PHP 7.2.5, the minimum version supported by the test
framework. The PHP 7.0 syntax check covers the shipped module separately.

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
  Office pages or written to logs. The notification retry token is the one
  exception: it is shown on the configuration page for optional host-level
  retries, and it authorizes only delivery of already-queued notifications.
- Signed purchase links are accepted once per shop; a replayed link fails like
  any other invalid link.
- Production archives send credentials only to `https://actions.bemo.now` and
  redirect only to `https://bemo.now`. Staging archives are locked to BEMO's
  staging API and `https://beta.bemo.now`. Developer-mode overrides still
  require HTTPS, except for explicit localhost URLs.
- Uninstall never disables the shop-wide Webservice setting because another
  integration may depend on it.

Please report security concerns privately to the repository maintainers rather
than opening a public issue with sensitive details.

## License

Licensed under the [Academic Free License 3.0](LICENSE.md).

Copyright © 2026 Beretag AG.

The release archive also includes [NOTICE.md](NOTICE.md), which identifies the
licensed work, source location, and bundled-dependency status. Installation or
distribution requirements under AFL section 9 should be confirmed with the
merchant's legal adviser or the distribution channel.
