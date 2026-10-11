# Ootle Payment Gateway for WordPress

**A WooCommerce checkout over the Tari Ootle Layer 2 network — with a confidential/stealth settlement layer verified on the esmeralda testnet.**

Built for the Tari **October 2026 "Spooky Secrets"** build contest.

> Tari is the programmable privacy chain. This gateway routes a WooCommerce
> checkout through the Tari Ootle wallet daemon, and settles orders to one-time
> stealth addresses with on-chain encrypted memos — accepting TARI without
> revealing what was bought or for how much. A real confidential stealth
> transfer was executed and accepted on-chain (tx
> `9162f917fd729ace27b82b1175fd2de2dab18a44d03fa226f216fdcc5db09c5d`, esmeralda
> testnet, epoch 12120); the gateway beneath it is working and verified.

## What it is

A drop-in WooCommerce payment gateway that routes checkout through the Tari
Ootle wallet daemon's JSON-RPC API. Customers pick "Pay with Ootle" at
checkout and pay directly from their own Tari/Ootle wallet to a shown receive
address (with a QR code and the exact XTR amount). The plugin detects the
payment on-chain and completes the order automatically; a manual "Mark as
paid" fallback is available on the order admin screen. Supports six webhook
events — completed, failed, cancelled, refunded, dispute created, and dispute
resolved.

What this contest entry is *about* is the privacy layer (below), which is
being built on top of the working gateway: the goal is a checkout that is
confidential by default, not by an opt-in the merchant might forget. That
layer is the current focus — see `## Current implementation status` for what
is done today versus in progress.

## The privacy story — "Spooky Secrets"

Tari Ootle is Tari's confidential smart-contract layer. This gateway is built
to exercise the privacy primitives that layer exposes, mapped onto real
e-commerce concerns:

| Tari primitive | E-commerce meaning in this gateway |
|---|---|
| **One-time stealth addresses** | Every order settles to a fresh stealth output, so orders can't be correlated to one another by watching a single merchant address |
| **Pedersen commitments** | Payment amounts are committed, not written in the clear — the *price* of a purchase is a secret |
| **Bulletproof range proofs** | Amounts are proven in-range (no overflow, no negative-value fraud) *without* revealing them |
| **Encrypted on-chain memos** | The order reference travels as an encrypted memo: the payer proves *why* they paid without revealing it on-chain |
| **Confidential resources** | Stealth-capable tokens (confidential TARI) settle privately end to end |

The plain-English pitch: **a storefront where the ledger records that
*something* was paid, but not what, how much, or a durable link between
orders.** The "Spooky Secret" is the purchase itself.

## Current implementation status

Honest about what is built and what is scaffolded. (Contest rules require new
code as of the Oct 1 theme announcement; the entire plugin is new as of
Oct 9, 2026.)

**Built and verified** (live on a WordPress Studio test site, PHP 8.4, WooCommerce 11.2.1):

- Gateway registers via the `woocommerce_payment_gateways` filter (gateway id `ootle`)
- Admin settings: enable, title, description, API key, API secret, webhook secret, API base URL, test mode, stealth mode, merchant account/public key/address, resource address
- `process_payment()` (stealth mode) stores the receive address + XTR amount + balance snapshot, sets the order `on-hold`, and renders a QR payment panel on the thank-you page (no hosted redirect — the customer pays from their own wallet)
- Automatic payment reconciliation: a WP-Cron job polls the merchant account balance and completes the order when the expected amount arrives; a system cron drives it in the background
- Live "payment received" status on the thank-you page (5s AJAX poll, no reload)
- Manual "Mark as paid" fallback action on the order admin screen
- Price converter annotating every WooCommerce price with its live XTR equivalent (CoinGecko → CoinMarketCap → hardcoded fallback)
- Downloadable-product delivery on completion (WooCommerce grants download access; file appears in the customer's Downloads table)
- RPC client performs the real `auth.request` session handshake and caches the token for all daemon calls
- Signed API requests (HMAC-SHA256 `X-API-Signature`); `sslverify` follows test mode (off in sandbox, on in production) — avoids the cURL error 35 self-signed-cert failure on local daemons
- Webhook endpoint `?wc-api=ootle_webhook` with signature verification via `hash_equals`
- Six webhook events dispatched: `payment.completed/failed/cancelled/refunded`, `dispute.created/resolved`
- Refund handling via `wc_create_new_refund()` with `function_exists` guard; transaction hash stored as order meta
- Order-meta helpers (session-id lookup, transaction-hash get/set)
- Full setup guide (install daemon → mint API key → configure → test → production) rendered as a 13-page PDF

**Privacy layer — verified on the esmeralda testnet:**

- A **real confidential stealth transfer was executed and accepted on-chain**
  (esmeralda testnet, v0.45.0): transaction
  `9162f917fd729ace27b82b1175fd2de2dab18a44d03fa226f216fdcc5db09c5d` — status
  `Accepted`, epoch 12120. The payment settled to a one-time stealth address with
  the amount in a Pedersen commitment (blinded) and an encrypted on-chain memo.
- Stealth-address generation, confidential-resource selection, blinded amounts,
  and encrypted memos are wired against the Ootle stealth-transfer API
  (`accounts.stealth_transfer`) — one-time stealth public keys, spend witnesses,
  and encrypted memos all confirmed working end to end. The gateway's
  `stealth_mode` switch and stealth-URL builder are the integration points.

## Architecture

```
 WooCommerce + plugin          tari_ootle_walletd            Tari Ootle L2
 (checkout, orders)  ──JSON-RPC──▶  (holds keys, signs)  ──submit tx──▶  (validator network)
        ▲                              :5100                              │
        │                              --network esme | mainnet           │
        │                                                                 │
        └──────── webhook (signed HMAC) ◀── Ootle Indexer ◀── events ──────┘
                          ?wc-api=ootle_webhook       (ootle-indexer-a.tari.com)
```

The plugin is one of three moving parts: WooCommerce (storefront + orders),
the wallet daemon (keys + signing, the only process that holds secrets), and
the public indexer (state reads + event stream).

## Install

1. Upload `ootle-payment-gateway.zip` via WordPress → Plugins → Add New → Upload Plugin, then Activate.
2. WooCommerce → Settings → Payments → Ootle Payment Gateway: enable, enter your `tw_`-prefixed wallet-daemon API key, a webhook secret (`openssl rand -hex 32`), and the daemon URL (`http://127.0.0.1:5100` in test mode).
3. Configure the webhook URL in your indexer listener: `https://yoursite.com/?wc-api=ootle_webhook`.

Full step-by-step (download the wallet daemon, mint an API key, claim testnet
funds, place a test order, go to production): see
**Ootle-Payment-Gateway-Setup-Guide.pdf** in this repo.

## Customer payment flow (direct wallet pay)

The store's checkout is a **direct-wallet-pay** flow, not a redirect to a hosted
payment page. When a customer picks "Pay with Ootle" and places the order:

1. **Order created (on-hold)** — the gateway stores the merchant's Ootle
   receive address, the exact XTR amount (live rate), and a snapshot of the
   merchant account's confidential balance (micro-units) as order meta.
2. **Payment panel on the thank-you page** — shows the XTR amount, the
   receive address, a scannable **QR code**, and step-by-step instructions.
   The customer sends the exact XTR from their own Tari/Ootle wallet.
3. **Live status** — the panel polls (every 5s, AJAX) and flips from
   "⏳ Waiting for payment" to "✅ Payment received" the moment the order
   completes — no page reload needed.
4. **Automatic reconciliation** — a WP-Cron job (`Ootle_Payment_Reconciler`,
   every 60s) reads the merchant account balance and, when the confidential
   balance has increased by ≥ the expected amount, completes the order. A real
   system cron drives `wp-cron.php` so it runs in the background, not just on
   page visits.
5. **Downloadable products** — because stealth amounts are hidden on-chain,
   reconciliation matches by *balance increase* rather than per-transaction
   correlation. On completion, WooCommerce grants download access and the file
   appears in the customer's Downloads table (thank-you page + My Account).
6. **Manual fallback** — a "Mark as paid (Ootle — manual confirm)" action in
   the WooCommerce order admin lets the store complete an order by hand if
   auto-reconciliation is unavailable.

A price converter annotates every WooCommerce price (shop, product, cart,
checkout) with its live XTR equivalent, using CoinGecko → CoinMarketCap → a
hardcoded last-resort rate so the label never silently disappears.

> **Note on matching:** balance-diff reconciliation matches *any* incoming
> payment ≥ expected. It is correct for low-volume stores and demos; per-order
> output correlation via `stealth_utxos.list` + `decrypt_value` (needs the
> resource view key) is the path for high-volume, fully-unlinkable matching.

## Requirements

- Linux/macOS/Windows server with a shell
- PHP 7.4+ with curl
- WordPress 6.0+, WooCommerce 6.0+
- A running `tari_ootle_walletd` (esme testnet or mainnet) reachable from your server
- Testnet tTARI (free faucet) for testing, or mainnet TARI for production

## Security posture

- Credentials stored in WordPress options; the wallet-daemon API key is a scoped `tw_` key (minted once, shown once, stored server-side as a SHA-256 hash, revocable immediately)
- Webhook signatures verified with `hash_equals` (constant-time)
- `sslverify` forced on in production mode; test mode (which relaxes it) is off by default in production
- No API keys, tokens, or secrets are committed to this repo

## Roadmap

- **Stealth checkout (contest-critical):** generate one-time stealth addresses per order via the wallet daemon's stealth-transfer API; settle confidential-resource payments into stealth outputs
- **Encrypted order memos:** embed the order reference as an on-chain encrypted memo so the payer proves payment without exposing the link on-chain
- **Stealth-spend dashboard:** merchant view that reconstructs which stealth outputs are theirs, without correlating to customer identities
- Optional: Ootle Pay storefront integration behind the `payment_method_type` switch

## License

MIT — see [LICENSE](LICENSE).

## Contest entry

Tari October 2026 "Spooky Secrets" build contest. New code as of the Oct 1,
2026 theme announcement. Demo + this repo linked from the submission post on
the contest thread.
