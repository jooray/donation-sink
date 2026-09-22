#!/usr/bin/env php
<?php
/**
 * Claim the wallet balance that predates the ledger for a project.
 *
 * Accounting can only count what it saw. Switch it on after months of donations and
 * the wallet holds real money no ledger row explains; it shows up as `unattributed`
 * the first time a melt drains it. That is honest, and useless when you know whose
 * money it is. This writes one donation row for the balance a pool already holds,
 * marked `opening`, so the dashboard attributes it and FIFO spends it first, which is
 * also true: it arrived before anything the ledger did see.
 *
 * It reads the wallet and never writes to it. The only write is one row in the
 * accounting database, and it refuses to do that twice for the same pool.
 *
 * Usage:
 *   php bin/opening-balance.php                                   # list pools holding money
 *   php bin/opening-balance.php --mint=https://mint.example --project=name
 *   php bin/opening-balance.php --mint=https://mint.example --project=name --commit
 *
 * Nothing is written without --commit.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This is a command line tool.\n");
}

require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';
require_once __DIR__ . '/../accounting.php';

use Cashu\Wallet;
use DonationSink\Accounting;

$opts = getopt('', ['mint::', 'unit::', 'project::', 'commit', 'config::', 'help']);
if (isset($opts['help'])) {
    exit(preg_replace('/^.*?\/\*\*\n|\s*\*\/.*$/s', '', file_get_contents(__FILE__)) . "\n");
}

$configPath = $opts['config'] ?? DonationSink\config_path(dirname(__DIR__));
if (!is_file($configPath)) {
    fwrite(STDERR, "No config at {$configPath}\n");
    exit(1);
}
$config = require $configPath;

$accounting = Accounting::fromConfig($config, static function (string $m): void {
    fwrite(STDERR, $m . "\n");
});
if ($accounting === null) {
    fwrite(STDERR, "Accounting is not enabled in config.php, so there is no ledger to write to.\n");
    exit(1);
}

$dbPath = $config['database_path'] ?? null;
if (!is_string($dbPath) || !is_file($dbPath)) {
    fwrite(STDERR, "No wallet database at " . var_export($dbPath, true) . "\n");
    exit(1);
}

// --- no mint named: say what is there, and stop -----------------------------------
//
// wallet_id is sha256(mintUrl + ':' + unit) truncated, so the pools cannot be listed
// by name from the file alone. What can be listed is how many there are and what each
// holds, which is enough to know whether one --mint call finishes the job.
if (empty($opts['mint'])) {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $rows = $pdo->query(
        "SELECT wallet_id, SUM(amount) AS balance, COUNT(*) AS proofs
           FROM cashu_proofs WHERE state = 'UNSPENT'
          GROUP BY wallet_id ORDER BY balance DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "No unspent proofs: nothing to claim.\n";
        exit(0);
    }
    echo "Pools holding unspent proofs (wallet_id is a hash of mint+unit, so name the\n";
    echo "mint with --mint and this tool will match it):\n\n";
    foreach ($rows as $r) {
        printf("  %s  %8d  (%d proofs)\n", $r['wallet_id'], (int)$r['balance'], (int)$r['proofs']);
    }
    echo "\nThen: php bin/opening-balance.php --mint=URL --unit=sat --project=NAME\n";
    exit(0);
}

$mint    = (string)$opts['mint'];
$unit    = (string)($opts['unit'] ?? 'sat');
$project = trim((string)($opts['project'] ?? ''));
if ($project === '') {
    fwrite(STDERR, "--project is required: an opening balance belongs to somebody.\n");
    exit(1);
}
[$normalised, $nameError] = Accounting::normaliseProject($project, $config);
if ($normalised === null) {
    fwrite(STDERR, "'{$project}' is not a usable project name: "
        . ($nameError ?? 'it normalises to nothing') . "\n");
    exit(1);
}

// Build the wallet exactly as donation-sink.php does, so getMintUrl() and getUnit()
// normalise identically. A ledger keyed on a trailing slash the wallet dropped would
// be a second pool that never reconciles.
$wallet = new Wallet($mint, $unit, $dbPath);
$poolMint = $wallet->getMintUrl();
$poolUnit = strtolower($wallet->getUnit());

$storage = $wallet->getStorage();
if ($storage === null) {
    fwrite(STDERR, "The wallet has no storage; check database_path in config.php.\n");
    exit(1);
}
if (!$storage->hasWalletData() && $storage->getSeedFingerprint() === null) {
    fwrite(STDERR, "No wallet exists for {$poolMint} ({$poolUnit}). Check the mint URL and unit.\n");
    fwrite(STDERR, "Derived wallet_id would be " . Wallet::deriveWalletId($mint, $unit) . "\n");
    exit(1);
}

// Read-only: the balance is a SUM over stored proofs. No seed is bound, nothing is
// minted, swapped or spent, and pending melts are deliberately left alone.
$balance = $wallet->getBalance();

echo "Pool      : {$poolMint} ({$poolUnit})\n";
echo "Wallet    : {$balance}\n";
echo "Ledger    : " . $accounting->donationCount($poolMint, $poolUnit) . " donation row(s)\n";
echo "Project   : {$normalised}\n";

if ($accounting->hasOpeningBalance($poolMint, $poolUnit)) {
    echo "\nAn opening balance has already been claimed for this pool. Nothing to do.\n";
    exit(0);
}
if ($balance <= 0) {
    echo "\nThe pool holds nothing. Nothing to claim.\n";
    exit(0);
}
if ($accounting->donationCount($poolMint, $poolUnit) > 0) {
    echo "\nWarning: this pool already has donation rows, so part of this balance is\n";
    echo "         probably already accounted for. Claiming all of it would double count.\n";
    echo "         Do this before the first donation, or not at all.\n";
    if (!isset($opts['commit'])) {
        exit(1);
    }
    echo "         --commit given; proceeding anyway.\n";
}

if (!isset($opts['commit'])) {
    echo "\nDry run. Would record {$balance} {$poolUnit} as an opening balance for "
       . "'{$normalised}'.\nRe-run with --commit to write it.\n";
    exit(0);
}

$id = $accounting->recordOpeningBalance($poolMint, $poolUnit, $balance, $normalised);
if ($id === null) {
    fwrite(STDERR, "\nThe row was not written. See the error above.\n");
    exit(1);
}
echo "\nRecorded donation #{$id}: {$balance} {$poolUnit} opening balance for '{$normalised}'.\n";
