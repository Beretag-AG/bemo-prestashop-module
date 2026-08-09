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
That conflicts with BEMO-359's declared 1.7.6/8.x runtime target. BEMO-363 is
therefore not ready for submission until the module gains and verifies a
PrestaShop 9 compatibility lane; direct ZIP installation for the pilot remains
independent of Marketplace review.

Docker compatibility checks (`BEMO-334`), Larise version discovery
(`BEMO-386`), and embedded checkout (`BEMO-333`/`BEMO-335`) are verification
or optional-capability work. They do not block this module foundation.

## Cross-repository invariants

- Technical module name: `bemoliveshopping`.
- One PrestaShop shop pairs with one authenticated BEMO creator account.
- The pairing token is 128-bit, expires after 15 minutes, and is single-use.
- `webhookSecret` authenticates shop-to-BEMO requests.
- `buyLinkSecret` authenticates BEMO-to-shop requests.
- Secrets are generated and rotated independently.
- Webhook handlers enqueue a stable event ID and return; network delivery is
  drained outside the merchant request.
- Webhook delivery is at-least-once and BEMO ingestion is idempotent.
- Link-out checkout is the guaranteed purchase mode. Embedding is an optional
  capability earned by separate browser and merchant acceptance tests.

## Signed purchase-link limits

The current signed purchase-link payload contains exactly `connectionId`,
`expiresAt`, `externalProductId`, `issuedAt`, `nonce`, `productId`, and
`sessionId`. It does not authorize a variant, voucher, quantity, cart,
customer, or redirect target. The PrestaShop module adds the signed product at
the shop's native minimum quantity, uses only that shop's configured default
combination when one is required, and enters the native checkout route. It does
not apply vouchers. A signed variant or voucher flow requires a separately
versioned contract before it can be implemented.

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

The committed contract suite includes JSON fixtures for pairing, every webhook
event, an exact-byte webhook HMAC vector, and a golden signed buy-link token.
The BEMO and module repositories each consume matching copies so either side
fails verification when the wire contract drifts.

## Webservice permission contract

Only `GET` is provisioned for:

- `products`
- `combinations`
- `stock_availables`
- `specific_prices`
- `cart_rules`

`orders` is deliberately excluded. Current sales-light policy counts checkout
intent instead of pretending pushed order events form a reconciled revenue
ledger.
