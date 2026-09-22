<?php
/**
 * Ten satoshis nobody could accept, taken as nine.
 *
 * A NUT-02 mint charges per input proof and rounds the whole swap up once, so on a
 * mint charging 100 ppk a 1 sat donation costs exactly 1 sat to redeem and nets its
 * recipient nothing. Ten of them cost that same one satoshi between them. This runs
 * the whole thing against a mint that really charges it: mint ten 1 sat tokens, hold
 * each one as it fails on its own, and watch the pool settle in a single swap.
 *
 * Run: php tests/held_donations.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/held.php';

use Cashu\Wallet;
use Cashu\CashuException;
use DonationSink\Held;

$passed = 0;
$failed = 0;
function check(bool $cond, string $what): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  ok: $what\n"; }
    else { $failed++; fwrite(STDERR, "  FAIL: $what\n"); }
}
function same($got, $want, string $what): void {
    check($got === $want, $what . ($got === $want ? '' : " (expected " . var_export($want, true)
        . ", got " . var_export($got, true) . ")"));
}

// --- a mint that charges for every proof ----------------------------------------
$dir = sys_get_temp_dir() . '/ds-held-' . bin2hex(random_bytes(4));
mkdir($dir);
$port = random_int(18100, 18999);
$mintUrl = "http://127.0.0.1:$port";
$server = proc_open(
    ['php', '-S', "127.0.0.1:$port", __DIR__ . '/mock_mint_router.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    dirname(__DIR__),
    ['MOCK_MINT_STATE' => $dir . '/mint.json'] + getenv()
);
check(is_resource($server), 'mock mint started');
register_shutdown_function(function () use ($server, $dir) {
    proc_terminate($server);
    exec('rm -rf ' . escapeshellarg($dir));
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    $ks = @json_decode((string)@file_get_contents("$mintUrl/v1/keysets"), true);
    if (!empty($ks['keysets'])) { $up = true; break; }
    usleep(100_000);
}
check($up, 'mock mint responds');

$seed = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
$wallet = new Wallet($mintUrl, 'sat', $dir . '/wallet.db');
$wallet->loadMint();
$wallet->initializeNewFromMnemonic($seed);
same($wallet->getInputFeePpk(), 100, 'the mint charges 100 ppk, so one proof rounds up to one satoshi');

/** Mint a fresh 1 sat token, as a donor would hand us one. */
$oneSat = function () use ($wallet, $mintUrl): string {
    $quote = $wallet->requestMintQuote(1);
    file_get_contents("$mintUrl/__control/pay", false, stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
        'content' => json_encode(['quote' => $quote->quote]),
    ]]));
    $proofs = $wallet->mint($quote->quote, 1);
    $token = $wallet->serializeToken($proofs);
    // The donor keeps them; they are not ours until a swap says so.
    $wallet->getStorage()->deleteProofs(array_map(fn($p) => $p->secret, $proofs));
    return $token;
};

$log = [];
$logger = function (string $m) use (&$log) { $log[] = $m; };
$config = ['database_path' => $dir . '/wallet.db',
           'held_donations' => ['enabled' => true, 'database_path' => $dir . '/held.db', 'max_fee_percent' => 10]];
$held = Held::fromConfig($config, $logger);
check($held instanceof Held, 'held donations open when switched on');
same(Held::fromConfig(['database_path' => $dir . '/wallet.db'], $logger), null,
    'and stay off when not, so this is opt-in');

// --- one token cannot pay for its own swap ---------------------------------------
echo "One 1 sat donation, on a mint that charges 1 sat to move it\n";
$first = $oneSat();
$refused = null;
try { $wallet->receive($first); } catch (CashuException $e) { $refused = $e->getMessage(); }
check($refused !== null && str_contains($refused, 'less than or equal to fee'),
    'the sink cannot take it alone, which is the whole problem');

// --- hold them until the pool pays for itself ------------------------------------
echo "Holding them instead\n";
$tokens = [$first];
for ($i = 0; $i < 9; $i++) { $tokens[] = $oneSat(); }

$settled = null;
foreach ($tokens as $n => $token) {
    $parsed = \Cashu\TokenSerializer::deserialize($token);
    $secrets = array_map(fn($p) => $p->secret, $parsed->proofs);
    same($held->hold($token, $mintUrl, 'sat', 1, $secrets, 'cashupayserver'), 'held',
        'donation ' . ($n + 1) . ' is kept');
    $result = $held->settle($wallet, $mintUrl, 'sat');
    if ($n < 8) {
        check($result === null, '  and with ' . ($n + 1) . ' held, swapping is still not worth it');
    } else {
        $settled = $result;
    }
}

echo "The pool settles in one swap\n";
check(is_array($settled), 'ten held donations settle');
same($settled['swapped'], 10, 'ten satoshis went in');
same($settled['fee'], 1, 'and the mint took one satoshi for the whole swap, not one each');
same($settled['credited'], 9, 'leaving nine, where swapping them one at a time left nothing');
same(count($settled['tokens']), 10, 'every donation is accounted for');
same(array_sum(array_column($settled['tokens'], 'credited')), 9,
    'and the shares add up to exactly what the mint gave back');
same($settled['tokens'][0]['project'], 'cashupayserver', 'each keeps the project that sent it');

same($held->pool($mintUrl, 'sat'), ['tokens' => 0, 'proofs' => 0, 'amount' => 0],
    'nothing is left held once it is swapped');
same(Wallet::sumProofs($wallet->getStoredProofs()), 9,
    'and the nine satoshis are now in the wallet, which is the first moment they are ours');

// --- the sender retries, because it believes the mint and not our HTTP status ----
echo "A sender that keeps retrying\n";
$again = $oneSat();
$parsed = \Cashu\TokenSerializer::deserialize($again);
$secrets = array_map(fn($p) => $p->secret, $parsed->proofs);
same($held->hold($again, $mintUrl, 'sat', 1, $secrets, null), 'held', 'a new donation is held');
same($held->hold($again, $mintUrl, 'sat', 1, $secrets, null), 'duplicate',
    'the same one offered again is recognised, not counted twice');
same($held->pool($mintUrl, 'sat')['tokens'], 1, 'so the pool holds it once');

// --- a donor who spends it back --------------------------------------------------
//
// This is the risk the feature takes: until the swap, the proofs are still the
// donor's to spend. One spent proof fails the whole batch, and a batch that always
// fails is a pool that never settles, so the mint is asked first.
echo "A donor who takes their ecash back\n";
$taken = $oneSat();
$takenProofs = \Cashu\TokenSerializer::deserialize($taken)->proofs;
$held->hold($taken, $mintUrl, 'sat', 1, array_map(fn($x) => $x->secret, $takenProofs), null);

// Really spend it: one proof cannot be swapped alone here either, so the donor does
// exactly what the sink does and spends it inside a batch of their own.
// A different seed, or the deterministic derivation re-issues secrets the sink's
// wallet already used and the mint refuses them as spent before the point is made.
$donorSeed = 'legal winner thank year wave sausage worth useful legal winner thank yellow';
$thief = new Wallet($mintUrl, 'sat', $dir . '/thief.db');
$thief->loadMint();
$thief->initializeNewFromMnemonic($donorSeed);
$thiefProofs = $takenProofs;
for ($i = 0; $i < 9; $i++) {
    $q = $thief->requestMintQuote(1);
    file_get_contents("$mintUrl/__control/pay", false, stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
        'content' => json_encode(['quote' => $q->quote]),
    ]]));
    $thiefProofs = array_merge($thiefProofs, $thief->mint($q->quote, 1));
}
$thief->swap($thiefProofs, Wallet::splitAmount(10 - $thief->calculateFee($thiefProofs)));
check(true, 'the donor spends the held proof inside a batch of their own');

// Now fill our pool around the proof that is no longer ours to spend.
for ($i = 0; $i < 9; $i++) {
    $t = $oneSat();
    $p = \Cashu\TokenSerializer::deserialize($t);
    $held->hold($t, $mintUrl, 'sat', 1, array_map(fn($x) => $x->secret, $p->proofs), null);
}
same($held->pool($mintUrl, 'sat')['tokens'], 11, 'eleven held, one of them already spent elsewhere');

$before = Wallet::sumProofs($wallet->getStoredProofs());
$result = $held->settle($wallet, $mintUrl, 'sat');
check(is_array($result), 'the pool still settles rather than failing on the spent proof');
same($result['swapped'], 10, 'the spent donation is dropped and the other ten go through');
same($result['fee'], 1, 'still one fee for the whole swap');
same(Wallet::sumProofs($wallet->getStoredProofs()) - $before, 9, 'nine more satoshis are ours');
same($held->pool($mintUrl, 'sat')['tokens'], 0,
    'and the donation that was taken back is forgotten rather than retried for ever');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
