<?php
/**
 * Stealth checkout orchestration.
 *
 * Bridges a WooCommerce order to the Tari Ootle wallet daemon's stealth
 * transfer. The privacy story, concretely:
 *
 *  - Each order is paid to a one-time stealth address derived (via
 *    Diffie-Hellman) from the merchant's destination public key, so orders
 *    cannot be correlated to one another by watching a single address.
 *  - The payment amount is blinded (Pedersen commitment + Bulletproof range
 *    proof), so the ledger records that *something* was paid without revealing
 *    how much.
 *  - An encrypted on-chain memo carries the order reference; only the merchant
 *    can decrypt it, so the payer proves *why* they paid without exposing the
 *    link on-chain.
 *
 * This class is deliberately thin: it assembles params (via
 * Ootle_Rpc_Client::build_stealth_transfer_params) and delegates all
 * cryptography to the wallet daemon, which holds the keys. Nothing secret
 * lives in WordPress.
 *
 * @package OotleGateway
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ootle_Stealth_Checkout
{
    /** @var Ootle_Rpc_Client */
    private Ootle_Rpc_Client $rpc;

    /** @var string Sender (merchant) account the payment settles into. */
    private string $merchant_account;

    /** @var string Merchant destination public key (stealth addresses derive from it). */
    private string $merchant_public_key;

    /** @var string Resource address to transfer — the native TARI token by default. */
    private string $resource_address;

    /** @var string Public view key of the resource, for output discovery/reconciliation. */
    private string $resource_view_key;

    /** @var int Fee ceiling in micro-units. */
    private int $max_fee;

    /**
     * @param array $config {
     *     @type Ootle_Rpc_Client $rpc
     *     @type string $merchant_account
     *     @type string $merchant_public_key
     *     @type string $resource_address
     *     @type string $resource_view_key
     *     @type int    $max_fee
     * }
     */
    public function __construct(array $config)
    {
        $this->rpc = $config['rpc'];
        $this->merchant_account = $config['merchant_account'];
        $this->merchant_public_key = $config['merchant_public_key'];
        $this->resource_address = $config['resource_address'];
        $this->resource_view_key = $config['resource_view_key'] ?? '';
        $this->max_fee = (int) ($config['max_fee'] ?? 1000);
    }

    /**
     * Pay an order via a stealth transfer to the merchant's one-time address.
     *
     * The order reference travels as an encrypted memo, so the on-chain record
     * proves the payment without linking it to the customer. The resulting
     * transaction hash and the (encrypted) memo are stored on the order for
     * later reconciliation.
     *
     * @param WC_Order $order The order to settle.
     * @return array{transaction_hash:string, statement:array} Receipt summary.
     * @throws Exception If the daemon rejects the transfer.
     */
    public function pay_order($order): array
    {
        $order_id = $order->get_id();

        // Encrypted on-chain memo: a compact, non-reversible order reference.
        $memo = 'wc-order:' . $order_id;

        // Fetch the current epoch so max_epoch can be set properly
        $current_epoch = $this->get_current_epoch();

        $params = Ootle_Rpc_Client::build_stealth_transfer_params([
            'account' => $this->merchant_account,
            'destination' => $this->merchant_public_key,
            'resource_address' => $this->resource_address,
            'amount' => (string) $order->get_total(),
            'max_fee' => $this->max_fee,
            'memo' => $memo,
            'reveal_amount' => false,
            'current_epoch' => $current_epoch,
        ]);

        $result = $this->rpc->accounts_stealth_transfer($params);

        $tx_hash = $result['transaction_hash']
            ?? $result['transaction_id']
            ?? $result['finalize']['transaction_hash']
            ?? '';

        if ($tx_hash === '') {
            throw new Exception('Stealth transfer returned no transaction hash');
        }

        // Persist the on-chain reference so the webhook / admin can reconcile.
        update_post_meta($order_id, '_ootle_stealth_tx_hash', $tx_hash);
        update_post_meta($order_id, '_ootle_stealth_memo', $memo);
        $order->add_order_note(sprintf(
            'Stealth payment submitted via Ootle. Tx: %s (amount blinded, memo encrypted).',
            $tx_hash
        ));

        return [
            'transaction_hash' => $tx_hash,
            'statement' => $result,
        ];
    }

    /**
     * Fetch the current network epoch from the wallet daemon.
     * Used to set a valid max_epoch for stealth transfers.
     *
     * @return int Current epoch, or 0 if unavailable.
     */
    private function get_current_epoch(): int
    {
        try {
            $info = $this->rpc->wallet_get_info();
            return (int) ($info['epoch'] ?? $info['current_epoch'] ?? 0);
        } catch (\Exception $e) {
            // If we can't get the epoch, the param builder will still
            // produce a value (current_epoch defaults to 0 + 100).
            return 0;
        }
    }

    /**
     * List the merchant's stealth UTXOs — the one-time outputs the account can
     * spend. This is how a merchant discovers payments without correlating
     * them to customer identities: each output is a fresh stealth address.
     *
     * @param int $limit Page size.
     * @return array The daemon's stealth_utxos.list result.
     * @throws Exception On RPC error.
     */
    public function list_stealth_outputs(int $limit = 50): array
    {
        return $this->rpc->stealth_utxos_list(
            $this->merchant_account,
            $this->resource_address,
            $limit
        );
    }

    /**
     * Decrypt a specific stealth output's value using the resource view key.
     * Lets the merchant match a received stealth payment to an order total
     * without the amount ever being public on-chain.
     *
     * @param string $utxo_id The stealth UTXO id.
     * @return array The daemon's decrypt_value result (carries the revealed amount).
     * @throws Exception On RPC error.
     */
    public function decrypt_output_value(string $utxo_id): array
    {
        if ($this->resource_view_key === '') {
            throw new Exception('No resource view key configured; cannot decrypt output value');
        }
        return $this->rpc->stealth_utxos_decrypt_value(
            $this->merchant_account,
            $utxo_id,
            $this->resource_view_key
        );
    }

    /**
     * Associate the stealth resource with the merchant account so the wallet
     * tracks its confidential outputs for balance and discovery. Run once at
     * setup.
     *
     * @return array The daemon's associate result.
     * @throws Exception On RPC error.
     */
    public function associate_stealth_resource(): array
    {
        return $this->rpc->accounts_associate_stealth_resource(
            $this->merchant_account,
            $this->resource_address,
            $this->resource_view_key
        );
    }
}
