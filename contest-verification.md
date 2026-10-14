# Contest Verification — Ootle Payment Gateway

## Live Stealth Transfer — CONFIRMED ON-CHAIN

Network: esmeralda (testnet)
Daemon version: 0.46.1
Date: 2026-10-14
Merchant account: component_435ef8101a17f5d5bd14921271a755a1bd17a23e17f94604124b4236756c3584

### Primary verified transaction

**transaction_id:** `dea894c80c62aeaeef5009acbe6ad177ad2597fbd60f3bf6bcb2e1f4b6f63b34`
**transaction_hash:** `dea894c80c62aeaeef5009acbe6ad177ad2597fbd60f3bf6bcb2e1f4b6f63b34`
**status:** Accepted
**epoch:** 12279
**final_fee:** 100000 µT (0.1 tTARI)
**total_fees_required:** 15231 µT
**total_fee_overcharge:** 84769 µT
**outcome:** Commit

Balance moved 164,679,548 → 164,579,548 µT confidential (exactly 100,000 µT = the fee).

### All transactions on the account

| Transaction ID | Status | Fee (µT) |
|---|---|---|
| be996ce075614f628147a114ecb72bc9d9f552d015261e1dd2272648851143c4 | Accepted | 100000 |
| 07d8046817409054e151723d8253e2d56c793b3bfcdbed30f6e78382a82e5f06 | Accepted | 100000 |
| 1fe5ff284ce9d0e43de71010b41756ba29178b4df9b319e4079abec0ae8fe2a1 | Accepted | 100000 |
| dea894c80c62aeaeef5009acbe6ad177ad2597fbd60f3bf6bcb2e1f4b6f63b34 | Accepted | 100000 |
| f9a68bd211eab8b1e6e07607c271a0baa18f0ebe221c65322df74cbc26d8b977 | Rejected | 0 |

### Fee breakdown (from the fee receipt)

total_fees_paid: 100000 µT (0.1 tTARI)
total_fees_required: 15231 µT
total_fee_overcharge: 84769 µT
exhaust_burn: 100000 µT (the ceiling was hit)
cost_breakdown:
  NativeExecution: 14142 µT
  RuntimeCall: 3 µT
  Storage: 883 µT
  SubstateCreate: 75 µT
  TransactionWeight: 128 µT

Note: the tx charged the full 100,000 µT ceiling, overcharging by 84,769 µT.
Lowering `max_fee` to ~20,000 µT would cut the per-tx cost by 80%.

### Cryptographic proof present in the accepted transaction

The on-chain transaction contains the full privacy stack:

- **Bulletproof range proof** — `agg_range_proof`, a 672-byte aggregated proof over both outputs
- **Pedersen commitments** — two output commitments (`a65c992c...`, `0ab62598...`); no plaintext amounts anywhere
- **Encrypted output data** — `encrypted_data` on each output; amounts hidden
- **Balance proof** — proves inputs equal outputs without revealing values
- **One revealed output** of 100,000 µT (the fee, which must be public to be paid)
- **Seal signature + transaction signature** — Ristretto255 Schnorr signatures
- **max_epoch: 12282** — bounded validity window

### Stealth transfer parameters used (verified wire format)

account: {"ComponentAddress": "component_..."}
owner_account: {"ComponentAddress": "component_..."}
resource_address: resource_0101010101010101010101010101010101010101010101010101010101010101
input_selection: PreferRevealed
transfers[]:
  - destination_address: otl_esm_...
    blinded_output_amount: <integer µT>
    revealed_output_amount: 0
    output_memo: {"Message": "..."}      (tagged enum, not a bare string)
    pay_to: StealthPublicKey
    attach_sender_address: false
    pay_ref: null
badge_usage: None
max_fee: 100000
max_epoch: <current_epoch + 200>
dry_run: false
fee_params: {input_selection: PreferRevealed, pay_fee_with_swap: null}

### Merchant balance after

revealed: 0 tTARI
confidential: 164.58 tTARI

### Reproduction

`live-transfer-test.php` performs the full sequence: bootstrap token → read
balance → fetch current epoch from the indexer → dry_run to price the tx →
submit → poll to Accepted → re-read balance.
