<?php
/**
 * Ootle Payment Reconciler.
 *
 * Detects when a customer has paid an on-hold Ootle order and completes it.
 *
 * How it works (confidential-transfer-aware):
 *   - At checkout, the gateway snapshots the merchant account's confidential
 *     balance (in micro-units) into the order meta `_ootle_balance_before`.
 *   - A WP-Cron job (every 60s) polls accounts.get_balances for the merchant
 *     account and compares the current confidential balance to the snapshot.
 *   - When the balance has increased by >= the expected amount (converted to
 *     micro-units), the payment is considered received and the order is
 *     completed automatically.
 *
 * Why balance-diff instead of per-tx matching: stealth/confidential transfers
 * hide individual amounts on-chain, so correlating a specific transaction to a
 * specific order is not possible. The balance increase is the reliable,
 * privacy-preserving signal.
 *
 * @package OotleGateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ootle_Payment_Reconciler
{
    const CRON_HOOK = 'ootle_reconcile_payments';
    const SCHEDULE = 'ootle_every_minute';

    public static function init(): void
    {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedule' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'reconcile_all' ] );

        // Ensure the cron event is scheduled.
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 30, self::SCHEDULE, self::CRON_HOOK );
        }
    }

    /**
     * Register a 1-minute cron schedule (WP's default minimum is hourly-ish via
     * its presets; we add an explicit minute interval).
     *
     * @param array $schedules
     * @return array
     */
    public static function add_cron_schedule( array $schedules ): array
    {
        $schedules[ self::SCHEDULE ] = [
            'interval' => 60,
            'display'  => __( 'Every minute (Ootle reconciler)', 'ootle-gateway' ),
        ];
        return $schedules;
    }

    /**
     * Scan on-hold Ootle orders and complete any whose payment has arrived.
     */
    public static function reconcile_all(): void
    {
        $orders = wc_get_orders( [
            'limit'        => 50,
            'status'       => 'on-hold',
            'payment_method' => 'ootle',
            'meta_key'     => '_ootle_expected_xtr',
        ] );

        if ( empty( $orders ) ) {
            return;
        }

        foreach ( $orders as $order ) {
            try {
                self::reconcile_order( $order );
            } catch ( Throwable $e ) {
                if ( function_exists( 'wc_get_logger' ) ) {
                    wc_get_logger()->error(
                        'Ootle reconcile failed for order ' . $order->get_id() . ': ' . $e->getMessage(),
                        [ 'source' => 'ootle-reconciler' ]
                    );
                }
            }
        }
    }

    /**
     * Check a single order against the current merchant balance.
     *
     * @param WC_Order $order
     */
    private static function reconcile_order( $order ): void
    {
        $expected_xtr = (float) $order->get_meta( '_ootle_expected_xtr' );
        $before       = (float) $order->get_meta( '_ootle_balance_before' );
        $resource     = $order->get_meta( '_ootle_payment_resource' );
        $settings     = get_option( 'woocommerce_ootle_settings', [] );
        $account      = trim( (string) ( $settings['merchant_account'] ?? '' ) );

        if ( $expected_xtr <= 0 || $account === '' || $resource === '' ) {
            return;
        }

        // Expected amount in micro-units (1 XTR = 1,000,000 µT).
        $expected_micro = (int) round( $expected_xtr * 1000000 );

        // Gateway settings live in a single option array.
        $settings  = get_option( 'woocommerce_ootle_settings', [] );
        $rpc_url   = trim( (string) ( $settings['api_base_url'] ?? '' ) );
        $rpc_token = trim( (string) ( $settings['api_key'] ?? '' ) );
        if ( $rpc_url === '' ) {
            return;
        }
        $rpc = new Ootle_Rpc_Client( $rpc_url, $rpc_token, false );
        $bal = $rpc->accounts_get_balances( $account );

        $current = 0;
        foreach ( $bal['balances'] ?? [] as $b ) {
            if ( ( $b['resource_address'] ?? '' ) === $resource ) {
                $current = (int) ( $b['confidential_balance'] ?? 0 );
                break;
            }
        }

        $received = $current - (int) $before;

        // Allow a tiny tolerance for fee dust; require >= 99.5% of expected.
        if ( $received >= (int) floor( $expected_micro * 0.995 ) ) {
            $order->update_status( 'completed', sprintf(
                'Payment received on-chain: %d micro-units (expected %d). Order completed automatically.',
                $received,
                $expected_micro
            ) );
            $order->add_order_note( 'Ootle payment confirmed via balance reconciliation.' );
            $order->save();
        }
    }
}
