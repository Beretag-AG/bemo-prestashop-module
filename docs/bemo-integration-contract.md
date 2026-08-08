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

## Proposed pairing request

The endpoint is not live on BEMO `staging`. BEMO-360 must settle the final URL
and response before this client is enabled.

```json
{
  "pairingToken": "base64url-128-bit-token",
  "shopUrl": "https://merchant.example",
  "platformVersion": "8.1.7",
  "phpVersion": "8.1.0",
  "languages": [{ "id": 1, "isoCode": "en" }],
  "currencies": [{ "id": 1, "isoCode": "EUR" }],
  "webserviceKey": "32-character-key",
  "webhookSecret": "shop-to-bemo-secret",
  "buyLinkSecret": "bemo-to-shop-secret"
}
```

The future contract suite must include JSON fixtures for pairing and every
webhook event, plus golden HMAC vectors containing exact raw body bytes and
expected signatures.

## Webservice permission contract

Only `GET` and `HEAD` are provisioned for:

- `products`
- `combinations`
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
