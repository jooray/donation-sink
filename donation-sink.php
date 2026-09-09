<?php
/**
 * Donation Sink - Cashu Token Receiver
 *
 * Accepts Cashu token donations via POST requests, swaps them to prevent re-spending,
 * and automatically melts to Lightning when per-mint balances reach configured thresholds.
 *
 * POST request format (JSON):
 *   {"token": "cashuBo2F0gaJhaU..."}
 *
 * Or form-encoded:
 *   token=cashuBo2F0gaJhaU...
 *
 * Response format:
 *   Success: {"status": "success", "message": "thank you"}
 *   Error: {"status": "error", "message": "error description"}
 */

require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';

use Cashu\Wallet;
use Cashu\TokenSerializer;
use Cashu\CashuException;
use Cashu\InsufficientBalanceException;

// Set JSON response header
header('Content-Type: application/json');

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server configuration error: config.php not found'
    ]);
    exit;
}

$config = require __DIR__ . '/config.php';

// Validate configuration
$requiredKeys = ['database_path', 'seed_phrase', 'lightning_address', 'melt_thresholds', 'log_path'];
foreach ($requiredKeys as $key) {
    if (!isset($config[$key])) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => "Server configuration error: missing $key"
        ]);
        exit;
    }
}

/**
 * Log a message to the configured log file
 */
function logMessage(string $message, array $config): void {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($config['log_path'], $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Strip anything that could forge a log line out of untrusted text.
 *
 * Mint URLs and units arrive inside a token supplied by whoever POSTs it, and they are
 * written straight into a line-oriented log file.
 */
function logSafe(string $value, int $max = 200): string {
    $clean = preg_replace('/[\x00-\x1f\x7f]/', ' ', $value) ?? '';
    return mb_substr($clean, 0, $max);
}

/**
 * Is this a mint we are willing to connect to?
 *
 * With `accepted_mints` configured, only those. Otherwise any public HTTPS mint — which
 * still rules out the loopback and private-network targets that make this endpoint
 * useful as an internal port scanner.
 */
function isAcceptableMint(string $mintUrl, array $config): bool {
    $parts = parse_url($mintUrl);
    if (!$parts || empty($parts['host']) || empty($parts['scheme'])) {
        return false;
    }

    $allowed = $config['accepted_mints'] ?? [];
    if (!empty($allowed)) {
        $canonical = fn(string $u) => rtrim(strtolower(trim($u)), '/');
        return in_array($canonical($mintUrl), array_map($canonical, $allowed), true);
    }

    // HTTPS only, except .onion, which cannot have a public certificate.
    $host = strtolower($parts['host']);
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'https' && !($scheme === 'http' && str_ends_with($host, '.onion'))) {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }

    // Every address the name resolves to must be public. A name resolving to 127.0.0.1
    // is the same attack wearing a hostname.
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
    foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $rec) {
        if (!empty($rec['ipv6'])) {
            $ips[] = $rec['ipv6'];
        }
    }
    if (empty($ips)) {
        return false; // cannot resolve: fail closed
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }

    return true;
}

/**
 * Send JSON response and exit
 */
function sendResponse(int $httpCode, string $status, string $message, array $config, ?string $logMsg = null): void {
    http_response_code($httpCode);
    echo json_encode([
        'status' => $status,
        'message' => $message
    ]);

    if ($logMsg) {
        logMessage($logMsg, $config);
    }

    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(405, 'error', 'Method not allowed. Use POST.', $config, null);
}

// Get token from request
$token = null;

// Try JSON body first
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $jsonBody = file_get_contents('php://input');
    $data = json_decode($jsonBody, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($data['token'])) {
        $token = $data['token'];
    }
}

// Fall back to POST parameter
if ($token === null && isset($_POST['token'])) {
    $token = $_POST['token'];
}

// Validate token was provided
if ($token === null || trim($token) === '') {
    sendResponse(400, 'error', 'Missing token parameter', $config, 'ERROR: Missing token in request');
}

$token = trim($token);

try {
    // Deserialize token to get mint and unit
    $tokenData = TokenSerializer::deserialize($token);
    $mintUrl = $tokenData->mint;
    $unit = $tokenData->unit;
    $amount = $tokenData->getAmount();

    // The mint URL comes out of a token a stranger POSTed, and the next thing this
    // script does is connect to it. Left unchecked that makes a public, unauthenticated
    // port scanner: the log for August 2026 is full of fabricated tokens naming
    // http://127.0.0.1:<random port>, each one probing whether something answers there.
    if (!isAcceptableMint($mintUrl, $config)) {
        sendResponse(400, 'error', 'This mint is not accepted', $config,
            'REJECTED: mint not accepted - ' . logSafe($mintUrl));
    }

    logMessage("INFO: Received donation - Mint: " . logSafe($mintUrl)
        . ", Unit: " . logSafe($unit) . ", Amount: $amount", $config);

    // Initialize wallet for this mint+unit combination
    $wallet = new Wallet($mintUrl, $unit, $config['database_path']);
    $wallet->loadMint();

    // Bind the seed to this wallet's storage. Which call is correct depends on what is
    // already there, and the library deliberately refuses to guess: getting it wrong is
    // how a wallet reissues secrets it has already used and destroys funds.
    $storage = $wallet->getStorage();
    if ($storage->getSeedFingerprint() !== null) {
        $wallet->initFromMnemonic($config['seed_phrase']);          // known, already ours
    } elseif ($storage->hasWalletData()) {
        $wallet->adoptSeedForExistingStorage($config['seed_phrase']); // predates fingerprints
    } else {
        $wallet->initializeNewFromMnemonic($config['seed_phrase']);   // brand new mint+unit
    }

    // Recover any pending melt operations before processing new donation
    $recovery = $wallet->recoverPendingMelts();
    if ($recovery['checked'] > 0) {
        logMessage("INFO: Recovered pending melts - Paid: {$recovery['paid']}, Restored: {$recovery['restored']}, Still pending: {$recovery['still_pending']}", $config);
    }

    // Receive (swap) the token - this prevents re-spending
    $newProofs = $wallet->receive($token);
    $receivedAmount = Wallet::sumProofs($newProofs);

    logMessage("SUCCESS: Swapped donation - Mint: $mintUrl, Unit: $unit, Amount: $receivedAmount", $config);

    // Get current balance for this mint+unit
    $allProofs = $wallet->getStoredProofs();
    $balance = Wallet::sumProofs($allProofs);

    logMessage("INFO: Current balance - Mint: $mintUrl, Unit: $unit, Balance: $balance", $config);

    // Check if we should auto-melt
    $threshold = $config['melt_thresholds'][$unit] ?? $config['default_melt_threshold'] ?? null;

    if ($threshold !== null && $balance >= $threshold) {
        // Estimate fee buffer: ~2% + 2 sats safety margin for Lightning routing fees
        $feeBuffer = max(3, (int)ceil($balance * 0.02) + 2);
        $meltAmount = $balance - $feeBuffer;

        logMessage("INFO: Balance ($balance) >= threshold ($threshold), attempting auto-melt of $meltAmount - Mint: $mintUrl, Unit: $unit", $config);

        try {
            // Melt balance (minus fee buffer) to Lightning address
            $meltResult = $wallet->payToLightningAddress($config['lightning_address'], $meltAmount);

            if ($meltResult['paid']) {
                $fee = $meltResult['fee'];
                $preimage = $meltResult['preimage'] ?? 'none';
                logMessage("SUCCESS: Auto-melt completed - Mint: $mintUrl, Unit: $unit, Amount: $meltAmount, Fee: $fee, Preimage: $preimage", $config);
            } else {
                logMessage("ERROR: Auto-melt failed (not paid) - Mint: $mintUrl, Unit: $unit, Amount: $meltAmount", $config);
            }

        } catch (InsufficientBalanceException $e) {
            // This shouldn't happen since we reserved a fee buffer, but log it anyway
            logMessage("ERROR: Auto-melt failed (insufficient balance) - Mint: $mintUrl, Unit: $unit, Amount: $meltAmount, Error: " . $e->getMessage(), $config);

        } catch (CashuException $e) {
            // Melt failed - log error but don't fail the donation
            // Tokens are still safely stored and will be retried on next donation
            logMessage("ERROR: Auto-melt failed - Mint: $mintUrl, Unit: $unit, Amount: $meltAmount, Error: " . $e->getMessage(), $config);
        }
    }

    // Return success response (even if melt failed, donation was accepted)
    sendResponse(200, 'success', 'thank you', $config, null);

} catch (CashuException $e) {
    // A token we could not accept. 400, not 500: the sender's input is the problem, and
    // a 500 tells an honest donor to keep retrying something that will never work.
    sendResponse(400, 'error', 'Token processing failed', $config,
        'ERROR: Token processing failed - ' . logSafe($e->getMessage(), 300));

} catch (Throwable $e) {
    // Genuinely our fault. Throwable, not Exception: a malformed token can raise a
    // TypeError, which would otherwise escape as an unlogged blank 500.
    sendResponse(500, 'error', 'Internal server error', $config,
        'ERROR: Unexpected error - ' . logSafe(get_class($e) . ': ' . $e->getMessage(), 300));
}
