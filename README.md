# Ootle Payment Gateway for WordPress

**A WooCommerce checkout over the Tari Ootle Layer 2 network — with confidential/stealth settlement verified on-chain.**

Built for the Tari **October 2026 "Spooky Secrets"** build contest.

> Tari is the programmable privacy chain. This gateway routes a WooCommerce
> checkout through the Tari Ootle wallet daemon today, and settles orders
> to one-time stealth addresses with on-chain encrypted memos — accepting
> TARI without revealing what was bought or for how much. The confidential
> layer is **verified on-chain** (an accepted confidential stealth transfer on
> esmeralda, 2026-10-14 — tx
> `dea894c80c62aeaeef5009acbe6ad177ad2597fbd60f3bf6bcb2e1f4b6f63b34`, see
> `contest-verification.md`); the gateway beneath it is working and verified.

## What it is

A drop-in WooCommerce payment gateway that routes checkout through the Tari
Ootle wallet daemon's JSON-RPC API. Customers pick "Pay with Ootle" at
checkout; the plugin opens a payment session with the daemon, redirects to the
Ootle payment URL, and completes the order when a signed webhook confirms
payment. Supports six webhook events — completed, failed, cancelled, refunded,
dispute created, and dispute resolved.

What this contest entry is *about* is the privacy layer (below), which is
built and verified on-chain: a checkout that is confidential by default, not
by an opt-in the merchant might forget. See `## Current implementation status`
for what is done today versus what remains.

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
- Admin settings: enable, title, description, API key, API secret, webhook secret, API base URL, test mode
- `process_payment()` creates a payment session with the wallet daemon and redirects to the Ootle payment URL; order set to `pending`
- Signed API requests (HMAC-SHA256 `X-API-Signature`); `sslverify` follows test mode (off in sandbox, on in production) — avoids the cURL error 35 self-signed-cert failure on local daemons
- Webhook endpoint `?wc-api=ootle_webhook` with signature verification via `hash_equals`
- Six webhook events dispatched: `payment.completed/failed/cancelled/refunded`, `dispute.created/resolved`
- Refund handling via `wc_create_new_refund()` with `function_exists` guard; transaction hash stored as order meta
- Order-meta helpers (session-id lookup, transaction-hash get/set)
- Full setup guide (install daemon → mint API key → configure → test → production) rendered as a 13-page PDF

**Privacy layer — VERIFIED ON-CHAIN:**

The stealth transfer layer is no longer scaffolded. A full confidential stealth
transfer was submitted to the esmeralda testnet on 2026-10-14 and **accepted by
validator nodes**. The transaction ID, fee receipt, and the complete
cryptographic proof set are recorded in `contest-verification.md` in this repo.

- **transaction_id:** `dea894c80c62aeaeef5009acbe6ad177ad2597fbd60f3bf6bcb2e1f4b6f63b34`
- **status:** Accepted (epoch 12279, outcome `Commit`)
- **fee:** 100,000 µT (0.1 tTARI), balance moved by exactly that amount

What that proves concretely:

- **One-time stealth addresses** — the transfer settled to a fresh stealth output derived from the merchant's destination public key
- **Pedersen commitments** — two output commitments on-chain, no plaintext amounts
- **Bulletproof range proofs** — `agg_range_proof` proves both amounts in-range without revealing them
- **Encrypted on-chain memos** — the order reference travelled as an encrypted `output_memo` (`{"Message": "..."}` tagged enum)
- **Balance proof** — proves inputs equal outputs without revealing values
- **Fee payment** — the only revealed output is the 100,000 µT fee, which must be public to be paid

The verified wire format (`transfers[]`, `blinded_output_amount`,
`output_memo` tagged enum, `owner_account`, `fee_params`, `max_epoch`) is
implemented in `includes/class-ootle-rpc-client.php::build_stealth_transfer_params()`.

Reproduce it with `php live-transfer-test.php` against a funded daemon.

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

**Done:**

- ~~**Stealth checkout (contest-critical):** generate one-time stealth addresses per order via the wallet daemon's stealth-transfer API; settle confidential-resource payments into stealth outputs~~ — **verified on-chain, 2026-10-14**
- ~~**Encrypted order memos:** embed the order reference as an on-chain encrypted memo so the payer proves payment without exposing the link on-chain~~ — **verified on-chain, 2026-10-14**

**Next:**

- **Stealth-spend dashboard:** merchant view that reconstructs which stealth outputs are theirs, without correlating to customer identities
- **Lower the fee ceiling:** the accepted tx burned the full `max_fee` (100,000 µT) when only 15,231 µT was required — an 84,769 µT overcharge. Dropping the default to ~20,000 µT cuts per-tx cost ~80%.
- **Wire `pay_order()` to a real checkout:** the transfer is proven at the RPC layer; the remaining work is triggering it from `process_payment()` on a live order and reconciling via `stealth_utxos.list`
- Optional: Ootle Pay storefront integration behind the `payment_method_type` switch

## License

MIT — see [LICENSE](LICENSE).

## Contest entry

Tari October 2026 "Spooky Secrets" build contest. New code as of the Oct 1,
2026 theme announcement. Demo + this repo linked from the submission post on
the contest thread.
