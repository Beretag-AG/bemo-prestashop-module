# BEMO Live Shopping for PrestaShop

[![CI](https://github.com/Beretag-AG/bemo-prestashop-module/actions/workflows/ci.yml/badge.svg)](https://github.com/Beretag-AG/bemo-prestashop-module/actions/workflows/ci.yml)

The official open-source PrestaShop integration for [BEMO](https://bemo.now).
It connects a merchant's catalog to their BEMO creator account so products can
be presented during paid live sessions while checkout remains on the
merchant's own storefront.

> [!IMPORTANT]
> This module is under active development and is not ready for production
> shops yet. The current version provides installation, configuration,
> credential provisioning, and the module side of account pairing. The BEMO
> claim screen, event delivery, and customer purchase flow are still being
> completed.

## Requirements

| Component | Supported versions |
| --- | --- |
| PrestaShop | 1.7.6 through 8.x |
| PHP | 7.2.5 through 8.1 |

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
- Removes only module-owned Webservice accounts during uninstall, including in
  multistore installations.
- Produces a deterministic ZIP that can be uploaded through Module Manager.

## Installation

There is no production release yet. To create a development build:

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

Do not test an unreleased build on a production shop.

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
