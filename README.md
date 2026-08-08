# BEMO Live Shopping for PrestaShop

`bemoliveshopping` connects a merchant's PrestaShop catalog to their BEMO
creator account. BEMO remains the live-session tool; the merchant remains the
seller of record and owns the storefront checkout.

This is the standalone, public module repository requested by
[BEMO-358](https://linear.app/watchonbemo/issue/BEMO-358). The current baseline
implements the installable foundation in
[BEMO-359](https://linear.app/watchonbemo/issue/BEMO-359):

- idempotent module schema installation and exact cleanup;
- a secret-safe back-office configuration page;
- an explicit administrator action that enables PrestaShop Webservice access;
- a dedicated 32-character Webservice key with `GET`/`HEAD` permissions only;
- separate 128-bit pairing, shop-to-BEMO webhook, and BEMO-to-shop buy-link
  secrets;
- adapter-level seams and unit tests that do not need a running shop;
- deterministic, correctly nested ZIP packaging.

Pairing delivery, durable webhooks, and the signed buy controller are tracked
separately by BEMO-360, BEMO-361, and BEMO-362. Their BEMO endpoints do not
exist on `staging` yet, so this repository does not invent live routes.

## Compatibility

- PrestaShop: `1.7.6.0` through `8.x`
- PHP: `7.2.5` or newer within PrestaShop's supported range

The explicit `8.99.99` upper bound scopes support to the PrestaShop 8 major
without claiming PrestaShop 9 compatibility. PHP 7.2.5 is the supported
overlap between the legacy target and PrestaShop 8; shops running PrestaShop
1.7.6 on older PHP must upgrade PHP before installing this module.

## Development

The local toolchain uses current PHP/Composer for dependency management and
keeps the two PrestaShop runtime edges available side-by-side:

```bash
brew tap shivammathur/php
brew install php php@8.1 shivammathur/php/php@7.2 composer
brew unlink php@7.2
brew link php
```

This leaves PHP 8.5 as the default while the matrix scripts resolve keg-only
PHP 7.2 and 8.1 directly. Dependencies are locked against PHP 7.2.5, preventing
Composer from selecting packages that cannot run on the oldest supported
module runtime.

Install dependencies and run the focused checks:

```bash
composer install
composer verify
```

Build the installable artifact with:

```bash
composer package
```

The result is `dist/bemoliveshopping.zip`. Its archive root contains
one `bemoliveshopping/` directory, as required by PrestaShop module uploads.

## Installation

1. Build or download the release ZIP.
2. In PrestaShop Back Office, open Module Manager and upload the ZIP.
3. Open the BEMO Live Shopping configuration page.
4. Enter the BEMO endpoint supplied for the environment.
5. Review and explicitly confirm Webservice enablement, then provision access.

The module never renders the generated Webservice key or directional secrets
back into HTML. Uninstall deletes only the exact Webservice account ID created
by this module; it does not disable the shop-wide Webservice because another
integration may depend on it.

## Security boundary

- Webservice access is read-only and excludes `orders`.
- Webhook and buy-link secrets are independent.
- Secrets are never placed in URLs or log messages.
- HTTPS is required except for explicit localhost development.
- A creator must still authenticate with BEMO to claim a pairing token.

See [the BEMO contract](docs/bemo-integration-contract.md) and the
[official-source research](docs/research/prestashop-module-development.md).

## License

Academic Free License 3.0 © Beretag AG.
