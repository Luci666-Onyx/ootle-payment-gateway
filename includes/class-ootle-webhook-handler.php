<?php
/**
 * Webhook event handler — maps Ootle payment events to WooCommerce order status changes
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ootle_Webhook_Handler
{
    /**
     * Process a webhook event and update the corresponding order.
     *
     * @param WC_Order $order
     * @param string $event_type
     * @param array $data
     */
    public static function process_event($order, $event_type, $data)
    {
        switch ($event_type) {
            case 'payment.completed':
                self::handle_payment_completed($order, $data);
                break;

            case 'payment.failed':
                self::handle_payment_failed($order, $data);
                break;

            case 'payment.cancelled':
                self::handle_payment_cancelled($order, $data);
                break;

            case 'payment.refunded':
                self::handle_payment_refunded($order, $data);
                break;

            case 'payment.dispute.created':
                self::handle_dispute_created($order, $data);
                break;

            case 'payment.dispute.resolved':
                self::handle_dispute_resolved($order, $data);
                break;

            default:
                // Unknown event — log for debugging but don't error
                $order->add_order_note("Received unhandled Ootle webhook event: {$event_type}");
                break;
        }
    }

    private static function handle_payment_completed($order, $data)
    {
        $tx_hash = $data['data']['transaction_hash'] ?? '';

        if ($tx_hash) {
            Ootle_Order_Meta::set_transaction_hash($order->get_id(), $tx_hash);
        }

        $order->payment_complete();
        $order->add_order_note(
            __('Payment confirmed via Ootle.', 'ootle-gateway') .
            ($tx_hash ? ' ' . sprintf(__('Transaction: %s', 'ootle-gateway'), $tx_hash) : '')
        );

        wc_reduce_stock_levels($order->get_id());
        $order->save();
    }

    private static function handle_payment_failed($order, $data)
    {
        $reason = $data['data']['failure_reason'] ?? '';
        $order->update_status('failed', __('Payment failed via Ootle.', 'ootle-gateway'));
        if ($reason) {
            $order->add_order_note(sprintf(__('Failure reason: %s', 'ootle-gateway'), $reason));
        }
    }

    private static function handle_payment_cancelled($order, $data)
    {
        $order->update_status('cancelled', __('Payment cancelled by customer via Ootle.', 'ootle-gateway'));
    }

    private static function handle_payment_refunded($order, $data)
    {
        $refund_amount = $data['data']['amount'] ?? 0;
        $tx_hash = $data['data']['refund_transaction_hash'] ?? '';

        // Ootle sends the refund in the order's currency; if the webhook payload
        // specifies a different currency, treat the amount as already converted.
        $amount = $refund_amount;

        if (!function_exists('wc_create_new_refund')) {
            $order->add_order_note(
                sprintf(__('Refund of %s via Ootle (transaction: %s) — manual refund required in WooCommerce admin.', 'ootle-gateway'), $amount, $tx_hash)
            );
            return;
        }

        $refund = wc_create_new_refund([
            'order_id' => $order->get_id(),
            'amount' => $amount,
            'reason' => __('Refund processed via Ootle', 'ootle-gateway'),
        ]);

        if ($refund) {
            if ($tx_hash) {
                update_post_meta($refund->get_id(), '_ootle_transaction_hash', $tx_hash);
            }
            $order->add_order_note(sprintf(__('Refund created via Ootle. Transaction: %s', 'ootle-gateway'), $tx_hash));
        }
    }

    private static function handle_dispute_created($order, $data)
    {
        $dispute_id = $data['data']['dispute_id'] ?? '';
        $reason = $data['data']['reason'] ?? '';

        $order->update_status('on-hold', __('Payment dispute initiated via Ootle.', 'ootle-gateway'));
        $order->add_order_note(
            sprintf(__('Dispute ID: %s', 'ootle-gateway'), $dispute_id) .
            ($reason ? ' ' . sprintf(__('Reason: %s', 'ootle-gateway'), $reason) : '')
        );
    }

    private static function handle_dispute_resolved($order, $data)
    {
        $resolution = $data['data']['resolution'] ?? 'resolved';

        if ($resolution === 'won' || $resolution === 'resolved_in_favor') {
            $order->add_order_note(__('Dispute resolved in merchant\'s favor.', 'ootle-gateway'));
        } else {
            $order->add_order_note(__('Dispute resolved. Funds will be returned.', 'ootle-gateway'));
            $order->update_status('refunded', __('Order refunded due to dispute resolution.', 'ootle-gateway'));
        }
    }
}
