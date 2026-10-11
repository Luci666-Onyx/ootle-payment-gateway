<?php
/**
 * Ootle Checkout — Payment Instructions on the order-received page.
 *
 * For orders paid with the Ootle gateway, shows the merchant receive address,
 * the exact XTR amount to send, a QR code, and step-by-step instructions so the
 * customer knows how to pay from their own wallet.
 *
 * The QR is rendered client-side with a tiny self-contained QR encoder (no CDN,
 * no external dependency) so it works offline.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render Ootle payment instructions on the WooCommerce thank-you (order-received) page.
 *
 * @param int $order_id
 */
function ootle_thankyou_payment_instructions( $order_id ) {
    if ( ! $order_id ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_payment_method() !== 'ootle' ) {
        return;
    }

    $address = $order->get_meta( '_ootle_receive_address' );
    $amount  = $order->get_meta( '_ootle_expected_xtr' );
    if ( ! $address ) {
        return;
    }

    $status      = $order->get_status();
    $is_completed = ( $status === 'completed' || $status === 'processing' );
    $downloads   = $order->get_downloadable_items();
    $has_downloads = ! empty( $downloads );

    // Build a payment URI the wallet can parse.
    $pay_uri = $address;

    // Localize the AJAX endpoint + order id for the status poller.
    // Build the Thank You page URL (used by the poller to redirect on payment).
    $thank_you_id = (int) get_option( 'ootle_thank_you_page_id', 0 );
    $thank_you_url = $thank_you_id ? get_permalink( $thank_you_id ) : '';
    if ( $thank_you_url ) {
        $thank_you_url = add_query_arg( [
            'order_id' => $order_id,
            'key'      => $order->get_order_key(),
        ], $thank_you_url );
    }

    $poll = [
        'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        'action'      => 'ootle_order_status',
        'nonce'       => wp_create_nonce( 'ootle_order_status_' . $order_id ),
        'orderId'     => $order_id,
        'thankYouUrl' => $thank_you_url,
    ];
    ?>
    <section class="ootle-pay-instructions" id="ootle-pay-panel" data-completed="<?php echo $is_completed ? '1' : '0'; ?>"
             style="margin:24px 0;padding:20px;border:1px solid #21262d;border-radius:12px;background:#161b22;">
      <h2 style="margin:0 0 6px;color:#f0f6fc;font-size:18px;">Pay with Ootle (XTR)</h2>

      <!-- Live status banner -->
      <div id="ootle-status" style="margin:0 0 16px;padding:10px 14px;border-radius:8px;font-size:13px;font-weight:600;
           <?php echo $is_completed
               ? 'background:#0d2818;color:#7ee787;border:1px solid #3fb950;'
               : 'background:#1c1300;color:#fbbf24;border:1px solid #b58900;'; ?>">
        <?php if ( $is_completed ) : ?>
          ✅ Payment received — your order is complete!
        <?php else : ?>
          ⏳ Waiting for payment… send the amount below, then keep this page open. It updates automatically.
        <?php endif; ?>
      </div>

      <?php if ( $is_completed && $has_downloads ) : ?>
        <div style="margin:0 0 16px;padding:12px 14px;border-radius:8px;background:#0c2d48;color:#79c0ff;border:1px solid #58a6ff;font-size:13px;">
          Your download<?php echo count($downloads) > 1 ? 's are' : ' is'; ?> ready — see the <strong>Downloads</strong> table below.
        </div>
      <?php endif; ?>

      <div id="ootle-pay-body" style="<?php echo $is_completed ? 'display:none;' : ''; ?>">
      <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
        <div style="flex:0 0 auto;">
          <div id="ootle-qr" data-uri="<?php echo esc_attr( $pay_uri ); ?>"
               style="width:180px;height:180px;background:#ffffff;border-radius:8px;padding:8px;"></div>
        </div>

        <div style="flex:1 1 260px;min-width:240px;">
          <div style="margin-bottom:14px;">
            <div style="color:#8b949e;font-size:11px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Amount to send</div>
            <div style="color:#7ee787;font-size:22px;font-weight:700;"><?php echo esc_html( $amount ); ?> XTR</div>
          </div>

          <div style="margin-bottom:14px;">
            <div style="color:#8b949e;font-size:11px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Send to this address</div>
            <div style="display:flex;gap:8px;align-items:center;">
              <code id="ootle-addr" style="flex:1;word-break:break-all;color:#e6edf3;background:#0d1117;border:1px solid #21262d;border-radius:6px;padding:8px 10px;font-size:12px;"><?php echo esc_html( $address ); ?></code>
              <button type="button" id="ootle-copy-addr" class="button"
                      style="background:#7952b3;color:#fff;border:none;border-radius:6px;padding:8px 12px;cursor:pointer;font-size:12px;">Copy</button>
            </div>
          </div>

          <ol style="color:#c9d1d9;font-size:13px;margin:0;padding-left:20px;line-height:1.7;">
            <li>Open your Tari/Ootle wallet.</li>
            <li>Send <strong style="color:#f0f6fc;"><?php echo esc_html( $amount ); ?> XTR</strong> to the address above (or scan the QR).</li>
            <li>Keep your transaction hash — the order completes once the payment confirms on-chain.</li>
          </ol>
        </div>
      </div>
      </div><!-- /#ootle-pay-body -->
    </section>

    <script>
    window.OOTLE_POLL = <?php echo wp_json_encode( $poll ); ?>;
    </script>
    <script>
    (function () {
        // Copy button (independent of the QR library).
        var btn = document.getElementById('ootle-copy-addr');
        var addr = document.getElementById('ootle-addr');
        if (btn && addr) {
            btn.addEventListener('click', function () {
                var text = addr.textContent.trim();
                function done(){ btn.textContent = 'Copied!'; setTimeout(function(){ btn.textContent = 'Copy'; }, 1500); }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done).catch(function(){ legacy(text, done); });
                } else { legacy(text, done); }
                function legacy(t, cb){
                    var ta=document.createElement('textarea'); ta.value=t; ta.style.position='fixed'; ta.style.opacity='0';
                    document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');}catch(e){} document.body.removeChild(ta); cb();
                }
            });
        }
    })();
    </script>

    <!-- Compiled QR Code generator library (Project Nayuki, MIT) -->
    <script>
    <?php
    // Inline the compiled QR library so there is no external dependency.
    $qr_lib = ootle_get_qr_library_js();
    echo $qr_lib; // phpcs:ignore WordPress.Security.EscapeOutput -- trusted static JS
    ?>
    function renderQR(container, text) {
        // qrlib.QrCode.encodeText(text, ecl) — ecl from qrlib.QrCode.Ecc
        var ecc = qrlib.QrCode.Ecc.MEDIUM;
        var qr = qrlib.QrCode.encodeText(text, ecc);
        var count = qr.size;
        var size = 8;
        var margin = 1;
        var canvas = document.createElement('canvas');
        canvas.width = canvas.height = (count + margin * 2) * size;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#000000';
        for (var r = 0; r < count; r++) {
            for (var c = 0; c < count; c++) {
                if (qr.getModule(c, r)) {
                    ctx.fillRect((c + margin) * size, (r + margin) * size, size, size);
                }
            }
        }
        container.innerHTML = '';
        canvas.style.width = '100%';
        canvas.style.height = '100%';
        canvas.style.borderRadius = '4px';
        container.appendChild(canvas);
    }

    // Render the QR now that the library is loaded.
    (function () {
        var el = document.getElementById('ootle-qr');
        if (!el) return;
        var uri = el.getAttribute('data-uri');
        try {
            renderQR(el, uri);
        } catch (e) {
            el.innerHTML = '<div style="color:#888;font-size:11px;text-align:center;padding:20px;">QR unavailable</div>';
        }
    })();

    // --- Live status poller: flip the banner to "paid" when the order completes.
    (function () {
        var panel = document.getElementById('ootle-pay-panel');
        if (!panel || panel.getAttribute('data-completed') === '1') return; // already paid
        var cfg = window.OOTLE_POLL;
        if (!cfg) return;

        var statusEl = document.getElementById('ootle-status');
        var bodyEl   = document.getElementById('ootle-pay-body');
        var attempts = 0;
        var MAX = 120; // ~10 min at 5s interval

        function markPaid() {
            if (statusEl) {
                statusEl.style.background = '#0d2818';
                statusEl.style.color = '#7ee787';
                statusEl.style.border = '1px solid #3fb950';
                statusEl.innerHTML = '✅ Payment received — redirecting to your download…';
            }
            if (bodyEl) bodyEl.style.display = 'none';
            panel.setAttribute('data-completed', '1');
            clearInterval(timer);
            // Redirect to the Thank You page (with the download) after a beat.
            if (cfg.thankYouUrl) {
                setTimeout(function () { window.location.href = cfg.thankYouUrl; }, 1200);
            }
        }

        function poll() {
            if (++attempts > MAX) { clearInterval(timer); return; }
            var body = new URLSearchParams({
                action: cfg.action,
                nonce: cfg.nonce,
                order_id: cfg.orderId
            });
            fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.success && d.data && d.data.paid) { markPaid(); }
                })
                .catch(function () { /* transient network error; keep polling */ });
        }
        var timer = setInterval(poll, 5000);
    })();
    </script>
    <?php
}
add_action( 'woocommerce_thankyou', 'ootle_thankyou_payment_instructions', 20 );
// NOTE: hook ONLY woocommerce_thankyou. Blocksy's order-received page also
// fires woocommerce_order_details_before_order_table, which caused the panel
// to render twice. The thankyou hook fires once and covers the whole page.

/**
 * Return the inlined QR Code generator library (Nayuki, MIT).
 * Kept as a separate function for readability.
 *
 * @return string
 */
function ootle_get_qr_library_js() {
    // QR library lives in the plugin's assets/ dir.
    $path = OOTLE_GATEWAY_PLUGIN_DIR . 'assets/qrcode.min.js';
    if ( ! file_exists( $path ) ) {
        return '';
    }
    return file_get_contents( $path );
}

/**
 * AJAX: return whether an Ootle order has been paid (status completed/processing).
 * Used by the order-received page poller to flip the banner without a reload.
 */
function ootle_ajax_order_status() {
    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    if ( ! $order_id || ! check_ajax_referer( 'ootle_order_status_' . $order_id, 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'bad request' ], 400 );
    }
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_payment_method() !== 'ootle' ) {
        wp_send_json_error( [ 'message' => 'not found' ], 404 );
    }
    $status = $order->get_status();
    wp_send_json_success( [
        'paid'      => ( $status === 'completed' || $status === 'processing' ),
        'status'    => $status,
        'downloads' => count( $order->get_downloadable_items() ),
    ] );
}
add_action( 'wp_ajax_ootle_order_status', 'ootle_ajax_order_status' );
add_action( 'wp_ajax_nopriv_ootle_order_status', 'ootle_ajax_order_status' );

/**
 * Shortcode [ootle_payment_complete] — Thank You page content.
 *
 * Reads ?order_id= and ?key= (same as the standard WooCommerce thank-you
 * permalink), verifies the order belongs to Ootle and is paid, then renders a
 * success card with the download link(s). Falls back to a generic message.
 *
 * @return string
 */
function ootle_payment_complete_shortcode() {
    $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
    $key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
    if ( ! $order_id || ! $key ) {
        return ootle_pc_card( 'Payment confirmation', 'This page shows your order confirmation. Open it from the link on your order receipt.', '#fbbf24', '#1c1300' );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_payment_method() !== 'ootle' || $order->get_order_key() !== $key ) {
        return ootle_pc_card( 'Order not found', 'We couldn\'t verify this order. Please use the exact link from your receipt.', '#ff7b72', '#3d1418' );
    }

    $status = $order->get_status();
    if ( ! in_array( $status, [ 'completed', 'processing' ], true ) ) {
        return ootle_pc_card( 'Payment not yet confirmed', 'Your payment is still being confirmed on-chain. This page updates once it arrives — you can also check My Account → Orders.', '#fbbf24', '#1c1300' );
    }

    $downloads = $order->get_downloadable_items();
    ob_start();
    ?>
    <div class="ootle-pc" style="max-width:640px;margin:24px auto;padding:28px;border:1px solid #3fb950;border-radius:14px;background:#0d2818;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
        <span style="font-size:28px;">✅</span>
        <h2 style="margin:0;color:#7ee787;font-size:22px;">Payment received — thank you!</h2>
      </div>
      <p style="color:#a7f3d0;margin:0 0 18px;font-size:14px;">
        Your Tari Ootle (XTR) payment for order <strong style="color:#fff;">#<?php echo esc_html( $order->get_order_number() ); ?></strong>
        has been confirmed on-chain.
      </p>

      <?php if ( ! empty( $downloads ) ) : ?>
        <h3 style="color:#7ee787;font-size:15px;margin:0 0 10px;">Your download<?php echo count( $downloads ) > 1 ? 's' : ''; ?></h3>
        <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:10px;">
          <?php foreach ( $downloads as $download ) : ?>
            <li style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 14px;background:#052e16;border:1px solid #14532d;border-radius:8px;">
              <span style="color:#e6edf3;font-size:14px;"><?php echo esc_html( $download['product_name'] ); ?></span>
              <a href="<?php echo esc_url( $download['download_url'] ); ?>" class="button"
                 style="background:#3fb950;color:#052e16;border:none;border-radius:6px;padding:8px 14px;font-weight:700;font-size:13px;text-decoration:none;white-space:nowrap;">Download</a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else : ?>
        <p style="color:#a7f3d0;font-size:14px;">You can review your order anytime in <strong>My Account → Orders</strong>.</p>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'ootle_payment_complete', 'ootle_payment_complete_shortcode' );

/**
 * Helper: render a simple status card for the Thank You shortcode.
 */
function ootle_pc_card( $title, $body, $color, $bg ) {
    return sprintf(
        '<div style="max-width:640px;margin:24px auto;padding:24px;border:1px solid %1$s;border-radius:12px;background:%2$s;">'
        . '<h2 style="margin:0 0 8px;color:%1$s;font-size:20px;">%3$s</h2>'
        . '<p style="margin:0;color:#c9d1d9;font-size:14px;">%4$s</p></div>',
        esc_attr( $color ), esc_attr( $bg ), esc_html( $title ), esc_html( $body )
    );
}
