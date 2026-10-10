<?php
/**
 * Tari Ootle wallet-daemon JSON-RPC client.
 *
 * Speaks the daemon's real transport — a JSON-RPC 2.0 envelope POSTed to
 * /json_rpc with an `Authorization: Bearer tw_...` API key — as opposed to the
 * hypothetical REST /v1 surface. Method names below are taken verbatim from
 * the daemon's RPC route table (applications/tari_walletd/src/server.rs) and
 * the wallet_daemon_client library, so this client calls the daemon as it
 * actually exists rather than as it might be wished to.
 *
 * Confirmed RPC surface used here:
 *   - accounts.stealth_transfer              (one-time stealth address payment)
 *   - accounts.create_stealth_transfer_statement (build the statement server-side)
 *   - accounts.associate_stealth_resource    (track a stealth resource on an account)
 *   - stealth_utxos.list                     (merchant output discovery)
 *   - stealth_utxos.decrypt_value            (reveal a known stealth output amount)
 *
 * @package OotleGateway
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ootle_Rpc_Client
{
    /** @var string Wallet-daemon base URL, e.g. http://127.0.0.1:5100 */
    private string $base_url;

    /** @var string The tw_-prefixed API key minted from the wallet daemon. */
    private string $api_key;

    /** @var bool When true, TLS verification is relaxed (local/sandbox daemons). */
    private bool $insecure;

    /** @var int Monotonic JSON-RPC request id. */
    private int $rpc_id = 0;

    /**
     * @param string $base_url Daemon base URL (no trailing slash).
     * @param string $api_key  Bearer API key.
     * @param bool   $insecure Skip TLS verify (test mode only).
     */
    public function __construct(string $base_url, string $api_key, bool $insecure = false)
    {
        $this->base_url = rtrim($base_url, '/');
        $this->api_key = $api_key;
        $this->insecure = $insecure;
    }

    /**
     * Perform a stealth transfer: send a confidential-resource payment to a
     * one-time stealth address derived from the destination public key.
     *
     * The daemon derives the Diffie-Hellman one-time stealth address, builds
     * the Pedersen commitments and range proofs, attaches the encrypted memo,
     * signs the stealth spend keys, and submits the transaction. What returns
     * is the transaction receipt.
     *
     * @param array $params See build_stealth_transfer_params() for the shape.
     * @return array Decoded JSON-RPC `result`.
     * @throws Exception On transport or RPC error.
     */
    public function accounts_stealth_transfer(array $params): array
    {
        return $this->call('accounts.stealth_transfer', $params);
    }

    /**
     * Build (but do not submit) a stealth transfer statement server-side.
     * Useful when a caller wants to inspect or batch statements before
     * embedding them in a manifest.
     *
     * @param array $params Same shape as accounts_stealth_transfer minus submission.
     * @return array The constructed StealthTransferStatement.
     * @throws Exception On transport or RPC error.
     */
    public function accounts_create_stealth_transfer_statement(array $params): array
    {
        return $this->call('accounts.create_stealth_transfer_statement', $params);
    }

    /**
     * Associate a stealth resource address with an account so the wallet
     * tracks its confidential outputs for balance and discovery.
     *
     * @param string $account          Account name or component address.
     * @param string $resource_address Resource to track.
     * @param string $view_key         The resource's public view key.
     * @return array Decoded JSON-RPC `result`.
     * @throws Exception On transport or RPC error.
     */
    public function accounts_associate_stealth_resource(string $account, string $resource_address, string $view_key): array
    {
        return $this->call('accounts.associate_stealth_resource', [
            'account' => $account,
            'resource_address' => $resource_address,
            'view_key' => $view_key,
        ]);
    }

    /**
     * List stealth UTXOs held by an account. This is the merchant-side
     * discovery call: it returns the one-time stealth outputs the account can
     * spend, without correlating them to customer identities on-chain.
     *
     * @param string      $account  Account name or component address.
     * @param string|null $resource Optional resource-address filter.
     * @param int         $limit    Page size.
     * @return array Decoded JSON-RPC `result`.
     * @throws Exception On transport or RPC error.
     */
    public function stealth_utxos_list(string $account, ?string $resource = null, int $limit = 50): array
    {
        $params = ['account' => $account, 'limit' => $limit];
        if ($resource !== null) {
            $params['resource_address'] = $resource;
        }
        return $this->call('stealth_utxos.list', $params);
    }

    /**
     * Decrypt the value of a known stealth output. The caller must hold the
     * resource's view key; the daemon returns the revealed amount without
     * exposing it on-chain. Used to reconcile which stealth output corresponds
     * to a given order total.
     *
     * @param string $account   Account that owns the output.
     * @param string $utxo_id   The stealth UTXO id to decrypt.
     * @param string $view_key  The resource's public view key.
     * @return array Decoded JSON-RPC `result` carrying the revealed amount.
     * @throws Exception On transport or RPC error.
     */
    public function stealth_utxos_decrypt_value(string $account, string $utxo_id, string $view_key): array
    {
        return $this->call('stealth_utxos.decrypt_value', [
            'account' => $account,
            'utxo_id' => $utxo_id,
            'view_key' => $view_key,
        ]);
    }

    /**
     * Build the params array for accounts.stealth_transfer from the pieces a
     * WooCommerce order provides. Kept separate from the transport so the
     * gateway can assemble (and unit-test) the payload without a live daemon.
     *
     * Amounts on the Ootle network are integer micro-units: 1 TARI = 1,000,000
     * µT. WooCommerce totals are decimal strings in the store currency, so we
     * scale to micro-units here.
     *
     * @param array $args {
     *     @type string $account          Sender account name/address.
     *     @type string $destination      Destination public key (one-time stealth addr derived from it).
     *     @type string $resource_address Resource to transfer (e.g. the native TARI token).
     *     @type string $amount           Decimal amount in whole units, e.g. "5.24".
     *     @type int    $max_fee          Fee ceiling in micro-units.
     *     @type int    $max_epoch        Latest epoch the tx may land in.
     *     @type string $memo             Optional encrypted on-chain memo (order ref).
     *     @type bool   $reveal_amount    If true the amount is a revealed (public) output.
     * }
     * @return array The JSON-RPC params payload.
     */
    public static function build_stealth_transfer_params(array $args): array
    {
        $micro = self::to_micro_units($args['amount']);

        // blinded_amount is the confidential (Pedersen-committed) portion;
        // revealed_amount is the public portion. A fully private payment puts
        // everything in blinded_amount and zero in revealed_amount.
        $revealed = !empty($args['reveal_amount']) ? $micro : 0;
        $blinded = !empty($args['reveal_amount']) ? 0 : $micro;

        $output = [
            'destination' => $args['destination'],
            'blinded_amount' => $blinded,
            'revealed_amount' => $revealed,
        ];
        if (!empty($args['memo'])) {
            $output['memo'] = $args['memo'];
        }

        $params = [
            'account' => $args['account'],
            'resource_address' => $args['resource_address'],
            'outputs' => [$output],
            'max_fee' => (int) ($args['max_fee'] ?? 1000),
            'max_epoch' => (int) ($args['max_epoch'] ?? 0),
        ];

        // A zero max_epoch means "no explicit cap"; the daemon picks the window.
        if ($params['max_epoch'] === 0) {
            unset($params['max_epoch']);
        }

        return $params;
    }

    /**
     * Convert a decimal amount string to integer micro-units (1 TARI = 1e6 µT).
     * Uses string maths to avoid float drift on money.
     *
     * @param string $amount Decimal amount, e.g. "5.24".
     * @return int Micro-units.
     */
    public static function to_micro_units(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '' || !is_numeric($amount)) {
            return 0;
        }
        // Split on the decimal point; pad/truncate to exactly 6 fractional digits.
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');
        [$whole, $frac] = array_pad(explode('.', $amount, 2), 2, '0');
        $frac = str_pad(substr($frac, 0, 6), 6, '0');
        $micro = ((int) $whole) * 1000000 + (int) $frac;
        return $negative ? -$micro : $micro;
    }

    /**
     * Issue a single JSON-RPC 2.0 call against the wallet daemon.
     *
     * @param string $method Dotted RPC method name, e.g. "accounts.stealth_transfer".
     * @param array  $params Method params (JSON-encoded as `params`).
     * @return array The `result` object from the JSON-RPC response.
     * @throws Exception On transport failure or a JSON-RPC error object.
     */
    private function call(string $method, array $params): array
    {
        $url = $this->base_url . '/json_rpc';

        $envelope = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => ++$this->rpc_id,
        ];

        $args = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'timeout' => 30,
            'sslverify' => !$this->insecure,
            'body' => wp_json_encode($envelope),
        ];

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new Exception('Ootle daemon connection failed: ' . $response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300) {
            $msg = is_array($decoded) && isset($decoded['error']['message'])
                ? $decoded['error']['message']
                : 'HTTP ' . $status;
            throw new Exception('Ootle daemon HTTP error (' . $status . '): ' . $msg);
        }

        if (!is_array($decoded)) {
            throw new Exception('Ootle daemon returned a non-JSON response');
        }

        // JSON-RPC error object takes precedence over any result.
        if (isset($decoded['error'])) {
            $code = $decoded['error']['code'] ?? 0;
            $message = $decoded['error']['message'] ?? 'unknown error';
            throw new Exception('Ootle daemon RPC error ' . $code . ': ' . $message);
        }

        return $decoded['result'] ?? [];
    }
}
