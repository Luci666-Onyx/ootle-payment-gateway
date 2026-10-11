<?php
/**
 * Ootle Checkout Tari Converter
 *
 * Shows the Tari (XTM) equivalent next to USD prices on the WooCommerce
 * checkout (and cart) page, using the live XTM/USD rate from CoinGecko's free
 * public API. The rate is cached in a transient (5 min) so we don't hammer the
 * API on every page load.
 *
 * Amounts: 1 XTM = 1,000,000 µT (micro-Tari). We display in XTM with the µT
 * figure available too.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fetch the live XTM -> USD rate, cached for 5 minutes.
 *
 * Tries CoinGecko's free API first, falls back to CoinMarketCap's public
 * data-api, and finally to a hardcoded last-resort rate so the converter
 * never silently disappears. Caches even a 0 rate (5 min) to avoid hammering.
 *
 * @return float XTM price in USD.
 */
function ootle_get_xtm_usd_rate() {
    $cached = get_transient( 'ootle_xtm_usd_rate' );
    if ( $cached !== false ) {
        return (float) $cached;
    }

    $rate = 0.0;

    // 1. CoinGecko (primary).
    $cg = wp_remote_get(
        'https://api.coingecko.com/api/v3/simple/price?ids=minotari&vs_currencies=usd',
        [ 'timeout' => 8, 'headers' => [ 'Accept' => 'application/json' ] ]
    );
    if ( ! is_wp_error( $cg ) ) {
        $body = json_decode( wp_remote_retrieve_body( $cg ), true );
        if ( isset( $body['minotari']['usd'] ) && $body['minotari']['usd'] > 0 ) {
            $rate = (float) $body['minotari']['usd'];
        }
    }

    // 2. CoinMarketCap public data-api (fallback, id 4258 = XTM).
    if ( $rate <= 0 ) {
        $cmc = wp_remote_get(
            'https://api.coinmarketcap.com/data-api/v3/cryptocurrency/detail?id=4258',
            [ 'timeout' => 8, 'headers' => [ 'Accept' => 'application/json', 'User-Agent' => 'Mozilla/5.0' ] ]
        );
        if ( ! is_wp_error( $cmc ) ) {
            $body = json_decode( wp_remote_retrieve_body( $cmc ), true );
            if ( isset( $body['data']['statistics']['price'] ) && $body['data']['statistics']['price'] > 0 ) {
                $rate = (float) $body['data']['statistics']['price'];
            }
        }
    }

    // 3. Hardcoded last-resort so the converter still works if both APIs fail.
    if ( $rate <= 0 ) {
        $rate = 0.00148;
    }

    set_transient( 'ootle_xtm_usd_rate', $rate, 5 * MINUTE_IN_SECONDS );

    return $rate;
}

/**
 * Print the rate + converter script on checkout/cart pages.
 */
function ootle_checkout_tari_converter() {
    // Run on all WooCommerce pages: shop archive, single product, cart, checkout.
    if ( ! function_exists( 'is_woocommerce' ) ) {
        return;
    }
    if ( ! ( is_woocommerce() || is_cart() || is_checkout() ) ) {
        return;
    }

    $rate = ootle_get_xtm_usd_rate();
    if ( $rate <= 0 ) {
        return; // no rate available; skip silently
    }
    ?>
    <script type="text/javascript">
    (function () {
        // XTR = Ootle L2 token. Rate is USD per 1 XTR.
        var XTR_USD = <?php echo json_encode( $rate ); ?>;

        function parseAmount(str) {
            // Only accept a clean single price like "$10.00" or "10.00".
            // Reject mixed text (e.g. "Test Product x 1") that would
            // concatenate stray digits into a nonsense number.
            var s = String(str).trim();
            var m = s.match(/(-?[\d,]*\.?\d+)/);
            if (!m) return 0;
            // If there are letters (other than a leading currency symbol) bail.
            var withoutNum = s.replace(/[\d,.\s]/g, '');
            if (/[a-zA-Z]/.test(withoutNum)) return 0;

            var raw = m[1];
            var hasComma = raw.indexOf(',') !== -1;
            var hasDot   = raw.indexOf('.') !== -1;
            var v;
            if (hasComma && !hasDot) {
                // Comma is the decimal separator (e.g. "$0,02" -> 0.02). This is
                // the store's price format; stripping the comma would turn 0,02
                // into 002 (=2.0) — a 100x error.
                v = parseFloat(raw.replace(',', '.'));
            } else {
                // Dot decimal (or both): treat comma as thousands separator.
                v = parseFloat(raw.replace(/,/g, ''));
            }
            return isNaN(v) ? 0 : v;
        }

        function usdToXtr(usd) {
            if (XTR_USD <= 0) return null;
            return usd / XTR_USD;
        }

        function fmt(n, dp) {
            return n.toLocaleString(undefined, {minimumFractionDigits: dp, maximumFractionDigits: dp});
        }

        function tariLabel(usd) {
            var xtr = usdToXtr(usd);
            if (xtr === null) return '';
            var dp = xtr < 1 ? 4 : 2;
            // XTR = Ootle L2 token (µT = micro-XTR). Keep it compact so it
            // never overflows narrow cart/checkout cells.
            return '≈ ' + fmt(xtr, dp) + ' XTR';
        }

        function annotate(el) {
            if (!el || el.getAttribute('data-tari-done')) return;
            var usd = parseAmount(el.textContent);
            if (usd <= 0) return;
            var label = tariLabel(usd);
            if (!label) return;
            el.setAttribute('data-tari-done', '1');
            var span = document.createElement('span');
            span.className = 'ootle-tari-equiv';
            span.textContent = label;
            el.appendChild(span);
        }

        function run() {
            // WooCommerce wraps every price in <span class="woocommerce-Price-amount">
            // (and the classic <span class="amount">). Annotate clean price
            // spans across shop, product, cart, and checkout.
            document.querySelectorAll(
                '.woocommerce-Price-amount, .amount'
            ).forEach(function (el) {
                annotate(el);
            });
        }

        if (document.readyState !== 'loading') run();
        else document.addEventListener('DOMContentLoaded', run);
        // Re-run after WooCommerce AJAX updates the totals.
        if (window.jQuery) {
            jQuery(document.body).on('updated_checkout updated_cart_totals updated_shipping_method', function(){ run(); });
        }
    })();
    </script>
    <style>
    /* XTR equivalent: a clean block line BELOW the price so it never wraps
       mid-price or breaks cart/checkout table alignment. */
    .ootle-tari-equiv {
        display: block;
        color: #9a67ea;
        font-size: 0.85em;
        white-space: nowrap;
        margin-top: 2px;
        font-weight: 500;
    }
    </style>
    <?php
}
add_action( 'wp_footer', 'ootle_checkout_tari_converter', 20 );
