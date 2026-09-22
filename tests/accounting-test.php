<?php
declare(strict_types=1);

/**
 * Tests for the accounting layer. No framework, no Composer: run it with
 *
 *     php tests/accounting-test.php
 *
 * The pure parts (project name handling, the proportional split) always run. The
 * ledger tests need a SCRATCH MariaDB or MySQL database and are skipped without one:
 *
 *     DONATION_SINK_TEST_DSN='mysql:host=127.0.0.1;port=3306;dbname=ds_test;charset=utf8mb4' \
 *     DONATION_SINK_TEST_USER=root DONATION_SINK_TEST_PASS= \
 *     php tests/accounting-test.php
 *
 * IT DROPS EVERY ds_* TABLE IN THAT DATABASE BEFORE IT STARTS. Point it at a throwaway
 * database and never at anything real.
 */

require_once __DIR__ . '/../accounting.php';

use DonationSink\Accounting;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $what): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL: $what\n";
}

function is_same($actual, $expected, string $what): void
{
    $good = $actual === $expected;
    if (!$good) {
        echo "  expected " . json_encode($expected) . ", got " . json_encode($actual) . "\n";
    }
    ok($good, $what);
}

// ---------------------------------------------------------------------------
// Project names
// ---------------------------------------------------------------------------

echo "Project names\n";

$cfg = [];
is_same(Accounting::normaliseProject('example-project', $cfg), ['example-project', null], 'a plain name is accepted');
is_same(Accounting::normaliseProject('NsiteClay', $cfg), ['nsiteclay', null], 'names are lowercased');
is_same(Accounting::normaliseProject('  spaced  ', $cfg), ['spaced', null], 'surrounding space is trimmed');
is_same(Accounting::normaliseProject('a.b_c-d9', $cfg), ['a.b_c-d9', null], 'dot, underscore and dash are allowed');
is_same(Accounting::normaliseProject(null, $cfg), [null, null], 'no project is not an error');
is_same(Accounting::normaliseProject('', $cfg), [null, null], 'an empty project is not an error');

foreach ([
    '../../etc/passwd'           => 'path traversal',
    '/etc/passwd'                => 'an absolute path',
    "a\nb"                       => 'a newline that could forge a log line',
    "a\0b"                       => 'a NUL byte',
    "o'brien"                    => 'a quote',
    'drop table ds_donations'    => 'a space',
    '<script>'                   => 'markup',
    '-leading-dash'              => 'a name that does not start alphanumeric',
    '.hidden'                    => 'a name starting with a dot',
    'caf\u{e9}'                  => 'a non-ASCII character',
    str_repeat('x', 65)          => 'a name over 64 characters',
] as $bad => $why) {
    [$name, $err] = Accounting::normaliseProject((string)$bad, $cfg);
    ok($name === null && $err !== null, "rejects $why");
}

is_same(Accounting::normaliseProject(null, ['project' => ['default' => 'house']]),
    ['house', null], 'an unnamed donation can fall back to a default project');
is_same(Accounting::normaliseProject('other', ['project' => ['allowed' => ['one', 'two']]])[0],
    null, 'an allowlist, when set, refuses everything else');
is_same(Accounting::normaliseProject('TWO', ['project' => ['allowed' => ['one', 'two']]]),
    ['two', null], 'an allowlist matches after normalisation');
is_same(Accounting::normaliseProject(str_repeat('x', 20), ['project' => ['max_length' => 10]])[0],
    null, 'max_length is honoured');

// ---------------------------------------------------------------------------
// Proportional split
// ---------------------------------------------------------------------------

echo "Proportional split\n";

is_same(Accounting::splitProportionally([50, 50], 100), [50, 50], 'an even split');
is_same(Accounting::splitProportionally([1, 1, 1], 100), [34, 33, 33], 'the leftover unit goes somewhere');
is_same(array_sum(Accounting::splitProportionally([7, 11, 13, 29], 997)), 997, 'the parts always sum to the total');
is_same(Accounting::splitProportionally([], 100), [], 'nothing to split');
is_same(Accounting::splitProportionally([5, 5], 0), [0, 0], 'nothing to give');
is_same(Accounting::splitProportionally([0, 0], 10), [0, 0], 'no weights means no shares');

// ---------------------------------------------------------------------------
// The ledger
// ---------------------------------------------------------------------------

$dsn = getenv('DONATION_SINK_TEST_DSN');
if (!is_string($dsn) || $dsn === '') {
    echo "\nLedger tests SKIPPED (set DONATION_SINK_TEST_DSN to a throwaway database)\n";
    echo "\n$passed passed, $failed failed\n";
    exit($failed === 0 ? 0 : 1);
}

echo "Ledger\n";

$log = [];
$config = [
    'accounting' => [
        'enabled'  => true,
        'dsn'      => $dsn,
        'username' => getenv('DONATION_SINK_TEST_USER') ?: null,
        'password' => getenv('DONATION_SINK_TEST_PASS') ?: null,
        'auto_migrate' => true,
    ],
];
$logger = function (string $m) use (&$log) {
    $log[] = $m;
};

$pdo = new PDO($dsn, $config['accounting']['username'], $config['accounting']['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['ds_settlement_allocations', 'ds_settlements', 'ds_donations', 'ds_projects', 'ds_meta'] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS $t");
}

$a = Accounting::fromConfig($config, $logger);
ok($a !== null, 'accounting comes up when enabled');

/** Sum one column of one table. */
$sum = function (string $sql, array $args = []) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return (int)$st->fetchColumn();
};

// The tables do not exist yet: the very first write has to create them.
$mint = 'https://mint.example.com';
$d1 = $a->recordDonation($mint, 'sat', 1000, 998, 'alpha');
ok($d1 !== null, 'the first donation creates the schema and is recorded');
is_same($sum('SELECT COUNT(*) FROM ds_donations'), 1, 'one donation row');
is_same($sum('SELECT COUNT(*) FROM ds_projects'), 1, 'naming a project created it, with no configuration');

$d2 = $a->recordDonation($mint, 'sat', 500, 500, 'beta');
$d3 = $a->recordDonation($mint, 'sat', 300, 300, null);
$d4 = $a->recordDonation($mint, 'usd', 250, 250, 'alpha');
ok($d2 && $d3 && $d4, 'further donations recorded');
is_same($sum('SELECT COUNT(*) FROM ds_projects'), 2, 'the unnamed donation created no project');

// Melt 1000 sat of the 1798 sat pool, costing 20 in fees: 1020 leaves the wallet.
$a->recordMelt($mint, 'sat', 1000, 20, 'quote-1', 'preimage-1', 'someone@example.com');

is_same($sum('SELECT SUM(gross) FROM ds_settlement_allocations'), 1020,
    'the whole spend is allocated');
is_same($sum('SELECT SUM(net) FROM ds_settlement_allocations'), 1000,
    'the net allocations sum to exactly what reached the destination');
is_same($sum('SELECT SUM(fee) FROM ds_settlement_allocations'), 20,
    'the fee allocations sum to exactly the fee');
is_same($sum('SELECT settled_amount FROM ds_donations WHERE id = ?', [$d1]), 998,
    'FIFO drained the oldest donation first, in full');
is_same($sum('SELECT settled_amount FROM ds_donations WHERE id = ?', [$d2]), 22,
    'and took the remainder from the next one');
is_same($sum('SELECT settled_amount FROM ds_donations WHERE id = ?', [$d3]), 0,
    'the youngest donation is untouched');
is_same($sum('SELECT settled_amount FROM ds_donations WHERE id = ?', [$d4]), 0,
    'a melt in one unit never touches another unit');
is_same($sum('SELECT COUNT(*) FROM ds_settlement_allocations WHERE donation_id IS NULL'), 0,
    'nothing was unattributed: the ledger covered the whole spend');

// Income is attributed at receipt, so it does not move when a melt happens.
$totals = $a->projectTotals('2000-01-01 00:00:00', '2100-01-01 00:00:00', 'sat', null);
$byProject = [];
foreach ($totals as $row) {
    $byProject[$row['project'] ?? '(unnamed)'] = $row;
}
is_same($byProject['alpha']['received'], 1000, 'alpha received the face value of its token');
is_same($byProject['alpha']['credited'], 998, 'the mint kept an input fee');
is_same($byProject['alpha']['input_fee'], 2, 'the input fee is visible');
is_same($byProject['alpha']['paid_out'] + $byProject['alpha']['routing_fee'], 998,
    'all of alpha has now left the wallet');
is_same($byProject['alpha']['outstanding'], 0, 'so alpha holds nothing');
is_same($byProject['beta']['outstanding'], 478, 'beta still holds most of its donation');
is_same($byProject['(unnamed)']['outstanding'], 300, 'the unnamed donation is intact');

foreach ($byProject as $name => $row) {
    is_same(
        $row['paid_out'] + $row['routing_fee'] + $row['adjusted'] + $row['outstanding'],
        $row['credited'],
        "the columns for $name decompose exactly"
    );
    is_same($row['input_fee'] + $row['credited'], $row['received'], "received reconciles for $name");
}

// A melt bigger than the ledger knows about: the excess belongs to nobody.
$a->recordMelt($mint, 'usd', 400, 0, 'quote-2', null, 'someone@example.com');
is_same($sum('SELECT SUM(gross) FROM ds_settlement_allocations WHERE donation_id IS NULL'), 150,
    'money received before the ledger existed lands in the unattributed bucket');
is_same($sum('SELECT settled_amount FROM ds_donations WHERE id = ?', [$d4]), 250,
    'and the donations it did know about are fully settled');

// The same quote cannot be recorded twice.
$before = $sum('SELECT COUNT(*) FROM ds_settlements');
$a->recordMelt($mint, 'sat', 1000, 20, 'quote-1', 'preimage-1', 'someone@example.com');
is_same($sum('SELECT COUNT(*) FROM ds_settlements'), $before, 'a repeated melt quote is refused');
is_same($sum('SELECT SUM(gross) FROM ds_settlement_allocations'), 1020 + 400,
    'and the refusal rolled back its allocations');

// Reconciliation: the wallet lost 200 sat we did not see leave.
// beta and the unnamed donation hold 478 + 300 = 778 sat.
$a->reconcile($mint, 'sat', 578);
is_same($sum('SELECT SUM(amount_spent) FROM ds_settlements WHERE reason = \'reconcile\''), 200,
    'the difference is booked as an adjustment');
is_same($sum('SELECT SUM(amount_paid) FROM ds_settlements WHERE reason = \'reconcile\''), 0,
    'an adjustment paid nobody');
is_same($sum('SELECT SUM(amount_credited - settled_amount) FROM ds_donations WHERE unit = \'sat\''), 578,
    'the ledger now agrees with the wallet');

// A wallet holding more than the ledger knows is not an error: it is money from
// before accounting was switched on.
$before = $sum('SELECT COUNT(*) FROM ds_settlements');
$a->reconcile($mint, 'sat', 99999);
is_same($sum('SELECT COUNT(*) FROM ds_settlements'), $before,
    'a wallet richer than the ledger needs no correction');

// Series and settlements come back without blowing up.
$series = $a->series('2000-01-01 00:00:00', '2100-01-01 00:00:00', 'day', 0, 'sat', null, null);
ok(count($series) >= 1, 'the time series returns buckets');
is_same(array_sum(array_column($series, 'received')), 1800, 'the series covers every sat donation');
$filtered = $a->series('2000-01-01 00:00:00', '2100-01-01 00:00:00', 'month', 3600, 'sat', null, 'beta');
is_same(array_sum(array_column($filtered, 'received')), 500, 'the series can be filtered to one project');
$unnamed = $a->series('2000-01-01 00:00:00', '2100-01-01 00:00:00', 'year', 0, 'sat', null, '');
is_same(array_sum(array_column($unnamed, 'received')), 300, 'and to the unnamed bucket');

ok(count($a->settlements('2000-01-01 00:00:00', '2100-01-01 00:00:00', null, null)) === 3,
    'settlements lists the melts and the adjustment');
$facets = $a->facets();
is_same($facets['units'], ['sat', 'usd'], 'facets report both units');

// A period the donations fall outside of reports nothing.
is_same($a->projectTotals('1990-01-01 00:00:00', '1990-01-02 00:00:00', null, null), [],
    'an empty period is empty');

// ---------------------------------------------------------------------------
// Failure isolation: a broken database must not throw at the caller.
// ---------------------------------------------------------------------------

echo "Failure isolation\n";

$broken = Accounting::fromConfig([
    'accounting' => [
        'enabled'  => true,
        'dsn'      => 'mysql:host=127.0.0.1;port=1;dbname=nope',
        'username' => 'nobody',
        'password' => 'nothing',
        'timeout'  => 1,
        'auto_migrate' => true,
    ],
], $logger);

$countBefore = count($log);
$result = $broken->recordDonation('https://mint.example.com', 'sat', 10, 10, 'alpha');
is_same($result, null, 'a dead database returns null instead of throwing');
ok(count($log) > $countBefore, 'and says so in the log');
$broken->recordMelt('https://mint.example.com', 'sat', 10, 1, 'q', null, null);
$broken->reconcile('https://mint.example.com', 'sat', 0);
is_same($broken->projectTotals('2000-01-01 00:00:00', '2100-01-01 00:00:00', null, null), [],
    'and the dashboard queries degrade to empty');
ok(true, 'nothing above threw');

// ---------------------------------------------------------------------------
// schema.sql, and the auto_migrate = false path an operator ends up on.
// ---------------------------------------------------------------------------

echo "schema.sql\n";

foreach (['ds_settlement_allocations', 'ds_settlements', 'ds_donations', 'ds_projects', 'ds_meta'] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS $t");
}

$strict = $config;
$strict['accounting']['auto_migrate'] = false;
$noMigrate = Accounting::fromConfig($strict, $logger);
$countBefore = count($log);
is_same($noMigrate->recordDonation('https://mint.example.com', 'sat', 10, 10, 'alpha'), null,
    'with auto_migrate off, a missing schema is an accounting failure and nothing more');
ok(count($log) > $countBefore, 'and it is logged');

$sqlFile = file_get_contents(__DIR__ . '/../schema.sql');
$sqlFile = implode("\n", array_filter(explode("\n", $sqlFile), static function ($line) {
    return strpos(ltrim($line), '--') !== 0;
}));
foreach (array_filter(array_map('trim', explode(";\n", $sqlFile))) as $stmt) {
    if ($stmt === '') {
        continue;
    }
    $pdo->exec(rtrim($stmt, ";\n "));
}
is_same((int)$pdo->query('SELECT COUNT(*) FROM ds_meta')->fetchColumn(), 1,
    'schema.sql runs and records its version');

$after = Accounting::fromConfig($strict, $logger);
$id = $after->recordDonation('https://mint.example.com', 'sat', 10, 10, 'alpha');
ok($id !== null, 'and the sink works against a hand-created schema with no CREATE privilege needed');

$off = Accounting::fromConfig(['accounting' => ['enabled' => false]], $logger);
is_same($off, null, 'accounting is off unless explicitly enabled');
is_same(Accounting::fromConfig([], $logger), null, 'and off when not configured at all');
is_same(Accounting::fromConfig(['accounting' => ['enabled' => true]], $logger), null,
    'enabled without a dsn stays off rather than half working');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
