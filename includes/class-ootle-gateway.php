<?php
/**
 * Main Ootle payment gateway class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ootle_WC_Gateway extends WC_Payment_Gateway
{
    public bool $test_mode;
    public ?string $api_key;
    public ?string $api_secret;
    public ?string $webhook_secret;
    public string $api_base_url;

    public function __construct()
    {
        $this->id = 'ootle';
        $this->method_title = 'Ootle Payment Gateway';
        $this->method_description = 'Accept payments via the Tari Ootle Layer 2 network';
        $this->icon = apply_filters('wc_ootle_icon', '');

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->api_key = $this->get_option('api_key');
        $this->api_secret = $this->get_option('api_secret');
        $this->test_mode = $this->get_option('test_mode') === 'yes';
        $this->webhook_secret = $this->get_option('webhook_secret');
        $this->api_base_url = $this->get_option('api_base_url') ?: OOTLE_API_BASE_URL;

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_ootle_webhook', [$this, 'handle_webhook']);
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable Ootle Gateway', 'ootle-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable Ootle payments on checkout', 'ootle-gateway'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Payment Method Title', 'ootle-gateway'),
                'type' => 'text',
                'description' => __('Title shown to customers during checkout', 'ootle-gateway'),
                'default' => 'Pay with Ootle',
            ],
            'description' => [
                'title' => __('Description', 'ootle-gateway'),
                'type' => 'textarea',
                'description' => __('Description shown at checkout', 'ootle-gateway'),
                'default' => 'Pay securely via the Tari Ootle network',
            ],
            'api_key' => [
                'title' => __('API Key', 'ootle-gateway'),
                'type' => 'password',
                'description' => __('Your Ootle API key', 'ootle-gateway'),
                'desc_tip' => true,
            ],
            'api_secret' => [
                'title' => __('API Secret', 'ootle-gateway'),
                'type' => 'password',
                'description' => __('Your Ootle API secret', 'ootle-gateway'),
                'desc_tip' => true,
            ],
            'webhook_secret' => [
                'title' => __('Webhook Secret', 'ootle-gateway'),
                'type' => 'password',
                'description' => __('Secret used to verify webhook signatures', 'ootle-gateway'),
                'desc_tip' => true,
            ],
            'api_base_url' => [
                'title' => __('API Base URL', 'ootle-gateway'),
                'type' => 'text',
                'description' => __('Ootle API base URL (default: https://api.ootle.com/v1)', 'ootle-gateway'),
                'default' => OOTLE_API_BASE_URL,
                'desc_tip' => true,
            ],
            'test_mode' => [
                'title' => __('Test Mode', 'ootle-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable test/sandbox mode', 'ootle-gateway'),
                'default' => 'yes',
            ],
            'stealth_mode' => [
                'title' => __('Stealth (Confidential) Payments', 'ootle-gateway'),
                'type' => 'checkbox',
                'label' => __('Settle orders to one-time stealth addresses with blinded amounts and encrypted memos', 'ootle-gateway'),
                'description' => __('When enabled, checkout uses the wallet daemon\'s accounts.stealth_transfer RPC instead of a plain redirect. Requires the fields below.', 'ootle-gateway'),
                'default' => 'no',
                'desc_tip' => true,
            ],
            'merchant_account' => [
                'title' => __('Merchant Account', 'ootle-gateway'),
                'type' => 'text',
                'description' => __('Wallet-daemon account name or component address that payments settle into', 'ootle-gateway'),
                'default' => '',
                'desc_tip' => true,
            ],
            'merchant_public_key' => [
                'title' => __('Merchant Public Key', 'ootle-gateway'),
                'type' => 'text',
                'description' => __('Destination public key; one-time stealth addresses are derived from it per order', 'ootle-gateway'),
                'default' => '',
                'desc_tip' => true,
            ],
            'resource_address' => [
                'title' => __('Resource Address', 'ootle-gateway'),
                'type' => 'text',
                'description' => __('Resource to transfer — e.g. the native TARI token resource address', 'ootle-gateway'),
                'default' => '',
                'desc_tip' => true,
            ],
            'resource_view_key' => [
                'title' => __('Resource View Key', 'ootle-gateway'),
                'type' => 'text',
                'description' => __('Public view key of the resource, for stealth-output discovery and reconciliation', 'ootle-gateway'),
                'default' => '',
                'desc_tip' => true,
            ],
            'max_fee' => [
                'title' => __('Max Fee (µT)', 'ootle-gateway'),
                'type' => 'text',
                'description' => __('Fee ceiling in micro-units (1 TARI = 1,000,000 µT)', 'ootle-gateway'),
                'default' => '1000',
                'desc_tip' => true,
            ],
        ];
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        try {
            // Stealth (confidential) path: settle to a one-time stealth address
            // with a blinded amount and an encrypted memo.
            if ($this->get_option('stealth_mode') === 'yes') {
                return $this->process_stealth_payment($order);
            }

            // Standard redirect path.
            $session = $this->create_payment_session($order);

            if (!isset($session['payment_url']) || empty($session['payment_url'])) {
                throw new Exception('Failed to create payment session');
            }

            $order->update_status('pending', 'Awaiting Ootle payment confirmation');
            update_post_meta($order_id, '_ootle_session_id', $session['session_id']);

            return [
                'result' => 'success',
                'redirect' => $session['payment_url'],
            ];
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            $order->add_order_note('Payment error: ' . $e->getMessage());
            return [
                'result' => 'fail',
                'redirect' => '',
            ];
        }
    }

    /**
     * Build the stealth checkout handler from this gateway's settings.
     *
     * @return Ootle_Stealth_Checkout
     * @throws Exception If a required stealth field is empty.
     */
    private function build_stealth_checkout(): Ootle_Stealth_Checkout
    {
        $account = trim((string) $this->get_option('merchant_account'));
        $pubkey = trim((string) $this->get_option('merchant_public_key'));
        $resource = trim((string) $this->get_option('resource_address'));

        if ($account === '' || $pubkey === '' || $resource === '') {
            throw new Exception('Stealth mode requires Merchant Account, Merchant Public Key, and Resource Address.');
        }

        $rpc = new Ootle_Rpc_Client(
            $this->api_base_url,
            (string) $this->api_key,
            $this->test_mode
        );

        return new Ootle_Stealth_Checkout([
            'rpc' => $rpc,
            'merchant_account' => $account,
            'merchant_public_key' => $pubkey,
            'resource_address' => $resource,
            'resource_view_key' => trim((string) $this->get_option('resource_view_key')),
            'max_fee' => (int) $this->get_option('max_fee') ?: 1000,
        ]);
    }

    /**
     * Settle an order via a stealth transfer. The order goes to pending until
     * the on-chain transaction confirms (via webhook or manual reconciliation).
     *
     * @param WC_Order $order
     * @return array WooCommerce process_payment result.
     * @throws Exception
     */
    private function process_stealth_payment($order): array
    {
        $checkout = $this->build_stealth_checkout();
        $receipt = $checkout->pay_order($order);

        $order->update_status('pending', 'Awaiting on-chain confirmation of stealth payment ' . $receipt['transaction_hash']);

        // No customer redirect: the merchant's wallet daemon submits the
        // transaction. The customer lands on the order-received page.
        return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    private function create_payment_session($order)
    {
        $endpoint = '/payments/create';
        $data = [
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'order_id' => $order->get_id(),
            'return_url' => wc_get_checkout_url(),
            'cancel_url' => wc_get_cart_url(),
            'webhook_url' => home_url('/wp-json/woocommerce-api/ootle-webhook'),
            'metadata' => [
                'customer_email' => $order->get_billing_email(),
                'billing_address' => [
                    'address' => $order->get_billing_address_1(),
                    'city' => $order->get_billing_city(),
                    'country' => $order->get_billing_country(),
                ],
            ],
        ];

        return $this->api_request($endpoint, $data, 'POST');
    }

    private function api_request($endpoint, $data = [], $method = 'GET')
    {
        $url = rtrim($this->api_base_url, '/') . $endpoint;

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_key,
        ];

        if ($method === 'POST' || $method === 'PUT') {
            $body = wp_json_encode($data);
            $headers['X-API-Signature'] = $this->generate_signature($body);
        }

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => !$this->test_mode,
        ];

        if ($method === 'POST' || $method === 'PUT') {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new Exception('API connection failed: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code >= 200 && $status_code < 300) {
            return $body;
        }

        $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error';
        throw new Exception('Ootle API error (' . $status_code . '): ' . $error_msg);
    }

    private function generate_signature($payload)
    {
        return hash_hmac('sha256', $payload, $this->api_secret);
    }

    public function handle_webhook()
    {
        $raw_data = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_OOTLE_SIGNATURE'] ?? '';

        if (!$this->verify_webhook_signature($raw_data, $signature)) {
            status_header(401);
            exit('Invalid signature');
        }

        $data = json_decode($raw_data, true);
        $event_type = $data['type'] ?? '';
        $session_id = $data['data']['session_id'] ?? '';

        $order_id = Ootle_Order_Meta::get_order_by_session_id($session_id);

        if (!$order_id) {
            status_header(404);
            exit('Order not found');
        }

        $order = wc_get_order($order_id);
        Ootle_Webhook_Handler::process_event($order, $event_type, $data);

        status_header(200);
        echo 'OK';
        exit;
    }

    private function verify_webhook_signature($payload, $signature)
    {
        $expected = hash_hmac('sha256', $payload, $this->api_secret);
        return hash_equals($expected, $signature);
    }
}