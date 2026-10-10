<?php
/**
 * Order meta helper — session ID lookups and payment metadata
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ootle_Order_Meta
{
    const META_KEY_SESSION = '_ootle_session_id';
    const META_KEY_TX_HASH = '_ootle_transaction_hash';
    const META_KEY_EXCHANGE_RATE = '_ootle_exchange_rate';

    public static function get_order_by_session_id($session_id)
    {
        global $wpdb;

        $order_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                self::META_KEY_SESSION,
                $session_id
            )
        );

        return $order_id ?: false;
    }

    public static function set_session_id($order_id, $session_id)
    {
        update_post_meta($order_id, self::META_KEY_SESSION, $session_id);
    }

    public static function get_session_id($order_id)
    {
        return get_post_meta($order_id, self::META_KEY_SESSION, true);
    }

    public static function set_transaction_hash($order_id, $tx_hash)
    {
        update_post_meta($order_id, self::META_KEY_TX_HASH, $tx_hash);
    }

    public static function get_transaction_hash($order_id)
    {
        return get_post_meta($order_id, self::META_KEY_TX_HASH, true);
    }

    public static function set_exchange_rate($order_id, $rate)
    {
        update_post_meta($order_id, self::META_KEY_EXCHANGE_RATE, $rate);
    }

    public static function get_exchange_rate($order_id)
    {
        return get_post_meta($order_id, self::META_KEY_EXCHANGE_RATE, true);
    }
}
