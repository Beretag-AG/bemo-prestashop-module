# BEMO integration contract

Status: implementation boundary, 8 August 2026.

This repository is the platform-specific PrestaShop side of BEMO's Shop
Integration project. Application behavior and merchant credentials on the
BEMO side remain in the private BEMO application repository. The repositories
share only versioned HTTP contracts and committed fixtures.

## Linear scope

| Issue | State at repository creation | Responsibility |
| --- | --- | --- |
| [BEMO-358](https://linear.app/watchonbemo/issue/BEMO-358) | Todo | Work package for the standalone public module |
| [BEMO-359](https://linear.app/watchonbemo/issue/BEMO-359) | Todo | Module foundation, explicit Webservice provisioning, secrets |
| [BEMO-360](https://linear.app/watchonbemo/issue/BEMO-360) | Todo | Pairing request client and matching BEMO endpoint |
| [BEMO-361](https://linear.app/watchonbemo/issue/BEMO-361) | Todo | Durable signed webhook outbox and BEMO receiver |
| [BEMO-362](https://linear.app/watchonbemo/issue/BEMO-362) | Backlog | Signed buy controller and link-out baseline |
| [BEMO-363](https://linear.app/watchonbemo/issue/BEMO-363) | Backlog | Validator preparation and Marketplace submission |

Current Marketplace policy requires new submissions to support PrestaShop 9.
That conflicts with BEMO-359's declared 1.7.3.1/8.x runtime target. BEMO-363 is
therefore not ready for submission until the module gains and verifies a
PrestaShop 9 compatibility lane; direct ZIP installation for the pilot remains
independent of Marketplace review.

Docker compatibility checks (`BEMO-334`), Larise version discovery
(`BEMO-386`), and embedded checkout (`BEMO-333`/`BEMO-335`) are verification
or optional-capability work. They do not block this module foundation.

## Cross-repository invariants

- Technical module name: `bemoliveshopping`.
- Multiple authenticated BEMO creators may independently pair the same
  PrestaShop shop. Each creator has a separate BEMO connection and disconnects
  independently; one verified webhook fans out to every active matching
  connection.
- The pairing token is 128-bit, expires after 15 minutes, and is single-use.
- `webhookSecret` authenticates shop-to-BEMO requests.
- `buyLinkSecret` authenticates BEMO-to-shop requests.
- Secrets are generated and rotated independently.
- Re-pairing the same creator and normalized shop refreshes only that creator's
  BEMO connection. The module's shop-scoped webhook and buy-link secrets are
  shared by all creator connections for that shop; rotating either requires
  every affected creator connection to pair again.
- Webhook handlers enqueue a stable event ID and return; network delivery is
  drained outside the merchant request.
- When a host runs the notification retry endpoint, webhook delivery is
  at-least-once and BEMO ingestion is idempotent. BEMO's recurring catalog read
  remains the correctness path when no local delivery runner is configured.
- Link-out checkout is the baseline purchase mode. Embedding is optional: the
  module requests it, then a BEMO admin records approval after separate browser
  and merchant acceptance tests.

## Signed cart contract

Version 2 contains exactly `version`, `cartId`, `connectionId`, `sessionId`,
`issuedAt`, `expiresAt`, `nonce`, and `items`. Each item contains exactly
`externalProductId`, optional `externalVariantId`, and `quantity`. A token holds
one to 25 unique lines, each quantity is 1 through 99, its lifetime is at most
15 minutes, and its nonce can be accepted only once per shop.

The module validates every line before it mutates the native cart. PrestaShop
remains authoritative for product activity, shop association, combination
ownership, minimum quantity, and available stock. Applying the desired cart is
idempotent: each matching native line is increased only until it reaches the
signed quantity. Existing larger quantities and unrelated lines remain intact.
The controller always opens the native cart and never applies vouchers or a
signed redirect target.

For embedded checkout, the signed route also issues a short-lived same-shop
marker. Once the resulting native cart page finishes loading, the module posts
exactly `{source: "bemo-prestashop", type: "checkout.ready", version: 1}` to
the configured BEMO app origin. BEMO accepts the message only from the iframe
window and the signed shop URL's exact origin; all other messages are ignored.

During the 0.7.0 rollout, the verifier also accepts the previous strict
single-product contract. Legacy links use the product's native default
combination and minimum quantity, keep the same TTL and single-use nonce rules,
and also land on the native cart. BEMO must issue only version 2 after its
cart-based flow is deployed.

## Pairing request

The module client follows the BEMO `staging` contract introduced by BEMO
commit `5adaea93`. The API base URL and browser app base URL are configured
separately because the HTTP action runs on the Convex site domain while the
merchant claims the connection in the BEMO web application.

```json
{
  "pairingToken": "base64url-128-bit-token",
  "shopUrl": "https://merchant.example",
  "platformVersion": "8.1.7",
  "languageId": 1,
  "languages": ["en"],
  "currencies": ["EUR"],
  "webserviceKey": "32-character-key",
  "webhookSecret": "shop-to-bemo-secret",
  "buyLinkSecret": "bemo-to-shop-secret"
}
```

Successful starts return HTTP `201` with an epoch-millisecond expiry:

```json
{
  "expiresAt": 1786200000000
}
```

The module then redirects to
`{appBaseUrl}/settings/creator/integrations?pair={pairingToken}`. The token is a
short-lived, single-use authorization code. BEMO applies `Referrer-Policy:
no-referrer` while it is in the URL and removes it with a history replacement
after a successful claim. Ambiguous network and rate-limit failures reuse the
same token and payload; BEMO returns the original expiry for that exact retry.
Definitive rejection clears the local attempt before a fresh token is created.

When the merchant reopens the module configuration page, the module posts the
same token to `/prestashop/pairing/status`. BEMO returns only `pending`,
`claimed`, or `expired`. A `claimed` response moves the local shop to
`connected` and deletes the raw token; an `expired` response returns the shop
to setup. The status response uses `Cache-Control: no-store` and never contains
credentials or creator identity.

## Catalog sync ownership

BEMO owns the recurring catalog schedule. It reads a connected shop every 15
minutes in steady state and every minute while its creator has a live or
preparing session. The module's product, price, stock, and voucher hooks add an
signed notification to a durable local queue. Delivery runs only through the
private retry endpoint or a scheduler hook, never during a storefront or Back
Office request. BEMO's recurring read is the correctness backstop when that
notification has not been delivered.

The module does not depend on PrestaShop's Cron tasks manager. Its private
token-authenticated retry URL remains available for a host that wants an
additional local retry schedule.

An authenticated product-link response also returns the current embedded
checkout request and module version, even for an empty product list. BEMO uses
that value as the reconciliation backstop. A `configuration.updated` event in
the durable outbox is the optional fast path. It uses the normal event envelope
with `resourceType` set to `configuration` and `resourceId` set to the shop ID.

The committed contract suite includes JSON fixtures for pairing, every webhook
event, an exact-byte webhook HMAC vector, and a golden signed buy-link token.
The BEMO and module repositories each consume matching copies so either side
fails verification when the wire contract drifts.

## Webhook request headers

Every outbound catalog event carries the HMAC of the exact request body plus the
signature scheme it was produced with:

```http
X-BEMO-Signature: <hex-HMAC-SHA256>
X-Bemo-Signature-Version: v1
```

The version header is additive. A receiver that only knows `v1` may ignore it,
but a future scheme change is expressed there rather than by silently altering
the signature format.

## Signed purchase links are single use

The module records the `nonce` of every accepted purchase link per shop and
refuses the second use of the same token. A replay is answered exactly like an
invalid or expired token: a redirect to the shop's `pagenotfound` page with no
distinguishing detail. Records expire with the token TTL and are purged by the
same drain that delivers queued events.

The module does not verify `connectionId` against a locally stored connection:
several BEMO creators may pair the same shop, so the module holds shop-scoped
secrets and no single connection identifier.

## Webservice permission contract

Only `GET` and `HEAD` are provisioned for:

- `products`
- `categories`
- `combinations`
- `product_option_values`
- `stock_availables`
- `specific_prices`
- `cart_rules`
- `images`
- `languages`
- `currencies`
- `shops`
- `taxes`
- `tax_rules`

`orders` is deliberately excluded. Current sales-light policy counts checkout
intent instead of pretending pushed order events form a reconciled revenue
ledger.

Module versions 0.5.0 and 0.7.0 repair the permissions of an already-provisioned key
during upgrade without rotating it, so an existing BEMO connection keeps
working.
