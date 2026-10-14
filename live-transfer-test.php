<?php
/**
 * Live stealth transfer test against the Ootle wallet daemon.
 *
 * Sends a small confidential transfer from the merchant account back to its
 * own stealth address, so no funds leave the wallet. Runs a dry_run first to
 * read the ACTUAL network fee, then submits for real and polls for the result.
 *
 * Prints a step-by-step trace. Never fabricates a hash.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

const RPC        = 'http://127.0.0.1:5100/json_rpc';
const API_KEY    = 'tw_ndG3P50UIE6jGhvSB1xSZap491XoSsWYJpzr_8ukvHc';
const ACCOUNT    = 'component_435ef8101a17f5d5bd14921271a755a1bd17a23e17f94604124b4236756c3584';
const RESOURCE   = 'resource_0101010101010101010101010101010101010101010101010101010101010101';
const DEST       = 'otl_esm_1mq3kfazwrzmxdf08m7r86f5yzj905ccttz9pktmur48u89khpg9tev47dqg37rjgk0vw4uykvcdjyluq5ag6t5j6w73dey070em9cfgdt66v5';
const MAX_FEE    = 100000;      // 0.1 tTARI ceiling
const AMOUNT     = 100000;      // 0.1 tTARI test transfer

function rpc(string $method, array $params, ?string $token = null, int $id = 1): array
{
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init(RPC);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_POSTFIELDS     => json_encode([
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => $id,
        ]),
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("transport failed (HTTP $code): $err");
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("non-JSON response: " . substr((string) $raw, 0, 300));
    }
    return $decoded;
}

function step(string $msg): void
{
    echo "\n=== $msg ===\n";
}

function show(array $d): void
{
    echo json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

try {
    // ---------------------------------------------------------------
    step('1. Bootstrap admin token');
    $r = rpc('auth.request', [
        'permissions' => ['Admin'],
        'credentials' => ['None' => null],
    ]);
    if (isset($r['error'])) {
        throw new RuntimeException('auth.request failed: ' . $r['error']['message']);
    }
    $token = $r['result']['token'];
    echo "token acquired: " . substr($token, 0, 12) . "...\n";

    // ---------------------------------------------------------------
    step('2. Confirm balance before sending');
    $r = rpc('accounts.get_balances', [
        'account' => ['ComponentAddress' => ACCOUNT],
    ], $token, 2);
    if (isset($r['error'])) {
        throw new RuntimeException('get_balances failed: ' . $r['error']['message']);
    }
    foreach ($r['result']['balances'] as $b) {
        printf(
            "  %s: revealed=%s confidential=%s\n",
            $b['token_symbol'] ?? '?',
            $b['balance'] ?? '?',
            $b['confidential_balance'] ?? '?'
        );
    }

    // ---------------------------------------------------------------
    step('3. Determine current epoch (needed for max_epoch)');
    $epoch = 0;
    // Try the indexer the daemon is connected to.
    foreach ([
        'https://ootle-indexer-a.tari.com/tip_info',
        'https://ootle-indexer-a.tari.com/health',
        'https://ootle-indexer-a.tari.com/',
    ] as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo "  $url -> HTTP $code\n";
        if ($body !== false && $code >= 200 && $code < 300) {
            $j = json_decode($body, true);
            if (is_array($j)) {
                // Look for an epoch-ish field
                foreach (['epoch', 'current_epoch', 'tip_epoch', 'height'] as $k) {
                    if (isset($j[$k]) && is_numeric($j[$k])) {
                        $epoch = (int) $j[$k];
                        echo "  found $k = $epoch\n";
                        break 2;
                    }
                }
            }
        }
    }
    if ($epoch === 0) {
        echo "  could not read epoch from indexer; will rely on daemon error feedback\n";
    }
    $max_epoch = $epoch > 0 ? $epoch + 200 : 0;
    echo "  max_epoch to use: " . ($max_epoch ?: '(omit / let daemon advise)') . "\n";

    // ---------------------------------------------------------------
    $build = static function (bool $dry, int $maxEpoch): array {
        $p = [
            'account'          => ['ComponentAddress' => ACCOUNT],
            'owner_account'    => ['ComponentAddress' => ACCOUNT],
            'resource_address' => RESOURCE,
            'input_selection'  => 'PreferRevealed',
            'transfers'        => [[
                'destination_address'    => DEST,
                'blinded_output_amount'  => AMOUNT,
                'revealed_output_amount' => 0,
                'output_memo'            => ['Message' => 'gateway-live-test'],
                'pay_to'                 => 'StealthPublicKey',
                'attach_sender_address'  => false,
                'pay_ref'                => null,
            ]],
            'badge_usage' => 'None',
            'max_fee'     => MAX_FEE,
            'dry_run'     => $dry,
            'fee_params'  => [
                'input_selection'    => 'PreferRevealed',
                'pay_fee_with_swap'  => null,
            ],
        ];
        if ($maxEpoch > 0) {
            $p['max_epoch'] = $maxEpoch;
        }
        return $p;
    };

    // ---------------------------------------------------------------
    step('4. DRY RUN — read the actual network fee');
    $r = rpc('accounts.stealth_transfer', $build(true, $max_epoch), $token, 3);
    if (isset($r['error'])) {
        echo "dry_run rejected:\n";
        show($r['error']);
        // A missing max_epoch usually comes back as a named field error.
        echo "\n>> retrying dry_run with a generous max_epoch fallback\n";
        $r = rpc('accounts.stealth_transfer', $build(true, 999999), $token, 4);
        if (isset($r['error'])) {
            throw new RuntimeException('dry_run failed: ' . $r['error']['message']);
        }
    }
    echo "dry_run ACCEPTED — fee estimate:\n";
    show($r['result']);

    // ---------------------------------------------------------------
    step('5. SUBMIT the real stealth transfer');
    $r = rpc('accounts.stealth_transfer', $build(false, $max_epoch), $token, 5);
    if (isset($r['error'])) {
        echo "submit rejected:\n";
        show($r['error']);
        throw new RuntimeException('submit failed: ' . $r['error']['message']);
    }
    echo "submit response:\n";
    show($r['result']);

    $txid = $r['result']['transaction_id']
        ?? $r['result']['transaction_hash']
        ?? $r['result']['finalize']['transaction_hash']
        ?? null;

    if ($txid === null) {
        echo "\n>> no transaction_id at top level; dumping all result keys: "
            . implode(', ', array_keys($r['result'])) . "\n";
    } else {
        echo "\nTRANSACTION ID: $txid\n";

        // -----------------------------------------------------------
        step('6. Poll for finalization');
        for ($i = 0; $i < 12; $i++) {
            sleep(5);
            $q = rpc('transactions.get', ['transaction_id' => $txid], $token, 6);
            if (isset($q['error'])) {
                echo "  poll $i: error " . $q['error']['message'] . "\n";
                continue;
            }
            $res = $q['result'];
            $status = $res['status'] ?? 'unknown';
            $fee    = $res['final_fee'] ?? null;
            echo "  poll $i: status=$status" . ($fee !== null ? " final_fee=$fee" : '') . "\n";
            if (in_array($status, ['Accepted', 'Rejected', 'Invalid'], true)) {
                echo "\n=== FINAL RESULT ===\n";
                show($res);
                break;
            }
        }
    }

    // ---------------------------------------------------------------
    step('7. Balance after');
    $r = rpc('accounts.get_balances', [
        'account' => ['ComponentAddress' => ACCOUNT],
    ], $token, 7);
    if (!isset($r['error'])) {
        foreach ($r['result']['balances'] as $b) {
            printf(
                "  %s: revealed=%s confidential=%s\n",
                $b['token_symbol'] ?? '?',
                $b['balance'] ?? '?',
                $b['confidential_balance'] ?? '?'
            );
        }
    }

} catch (Throwable $e) {
    echo "\n!!! FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
