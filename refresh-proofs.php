<?php
/**
 * Refresh Proofs - Wrapper for donation-sink
 *
 * This script uses the config.php for database path and seed phrase,
 * making it easier to use with the donation-sink project.
 *
 * Usage:
 *   php refresh-proofs.php <mint_url> <unit>           # Sync proof states
 *   php refresh-proofs.php <mint_url> <unit> --restore # Also restore counters
 *   php refresh-proofs.php --list                      # List all wallets
 *
 * Examples:
 *   php refresh-proofs.php https://stablenut.cashu.network sat
 *   php refresh-proofs.php https://stablenut.cashu.network sat --restore
 *
 * For a standalone version without config.php, see:
 *   cashu-wallet-php/examples/refresh_wallet.php
 */

// Load config
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    echo "\033[31mError: config.php not found\033[0m\n";
    echo "This script requires config.php with 'database_path' and 'seed_phrase' settings.\n";
    echo "For a standalone version, use: cashu-wallet-php/examples/refresh_wallet.php\n";
    exit(1);
}

$config = require $configPath;

if (!isset($config['database_path']) || !isset($config['seed_phrase'])) {
    echo "\033[31mError: config.php must contain 'database_path' and 'seed_phrase'\033[0m\n";
    exit(1);
}

// Build arguments for the library script
$libraryScript = __DIR__ . '/cashu-wallet-php/examples/refresh_wallet.php';

// Parse our arguments
$args = array_slice($argv, 1);
$hasRestore = in_array('--restore', $args);
$hasList = in_array('--list', $args);
$hasHelp = in_array('--help', $args) || in_array('-h', $args);

// Filter out flags to get positional args
$positionalArgs = array_filter($args, fn($a) => !str_starts_with($a, '--') && $a !== '-h');
$positionalArgs = array_values($positionalArgs);

if ($hasHelp) {
    echo <<<HELP
Refresh Proofs - Sync local proof state with mint (donation-sink wrapper)

Usage:
  php refresh-proofs.php <mint_url> <unit>           Sync proof states
  php refresh-proofs.php <mint_url> <unit> --restore Also restore counters
  php refresh-proofs.php --list                      List all wallets

Examples:
  php refresh-proofs.php https://stablenut.cashu.network sat
  php refresh-proofs.php https://stablenut.cashu.network sat --restore

This wrapper automatically uses database_path and seed_phrase from config.php.
For a standalone version, see: cashu-wallet-php/examples/refresh_wallet.php

HELP;
    exit(0);
}

// Build command for the library script
$cmd = [PHP_BINARY, $libraryScript, $config['database_path']];

if ($hasList) {
    $cmd[] = '--list';
} else {
    if (count($positionalArgs) < 2) {
        echo "\033[31mError: Missing required arguments.\033[0m\n";
        echo "Usage: php refresh-proofs.php <mint_url> <unit> [--restore]\n";
        echo "       php refresh-proofs.php --list\n";
        echo "       php refresh-proofs.php --help\n";
        exit(1);
    }

    $cmd[] = $positionalArgs[0]; // mint_url
    $cmd[] = $positionalArgs[1]; // unit
    $cmd[] = $config['seed_phrase'];

    if ($hasRestore) {
        $cmd[] = '--restore';
    }
}

// Execute the library script
$escapedCmd = implode(' ', array_map('escapeshellarg', $cmd));
passthru($escapedCmd, $exitCode);
exit($exitCode);
