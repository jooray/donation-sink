<?php
declare(strict_types=1);

/**
 * Donation dashboard: income per project, per period, per mint, per unit.
 *
 * Read-only. It never touches the wallet, never melts anything and never writes to the
 * accounting database. The worst a bug in here can do is show a wrong number.
 *
 * IT HAS NO LOGIN OF ITS OWN. Authentication belongs to the web server — HTTP basic
 * auth, configured in the vhost (nginx) or in .htaccess (Apache). See README.md,
 * "Protecting the dashboard". As a fuse against the obvious accident, the page refuses
 * to render unless the web server tells it who the visitor is; that is a check that the
 * protection is switched on, not a substitute for it.
 */

require_once __DIR__ . '/accounting.php';

use DonationSink\Accounting;

$configPath = DonationSink\config_path(__DIR__);
if (!file_exists($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Server configuration error: config.php not found\n";
    exit;
}
$config = require $configPath;

$dash = $config['dashboard'] ?? [];
if (!is_array($dash)) {
    $dash = [];
}

// Off unless switched on. A clone of this repository does not serve its income to the
// internet because somebody deployed the tree before configuring the web server.
if (empty($dash['enabled'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

// Did the web server authenticate anybody? REMOTE_USER is what Apache and a correctly
// configured nginx pass through; PHP_AUTH_USER is what PHP derives from an
// Authorization header. Either one means basic auth is in front of us.
$remoteUser = $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? $_SERVER['PHP_AUTH_USER']
    ?? null;

if (($dash['require_web_auth'] ?? true) && ($remoteUser === null || $remoteUser === '')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This dashboard must be protected by HTTP basic auth in the web server, and the\n"
       . "web server is not passing an authenticated user. Configure it (see README.md,\n"
       . "\"Protecting the dashboard\"), or set dashboard.require_web_auth to false in\n"
       . "config.php if you have protected it some other way.\n";
    exit;
}

$accounting = Accounting::fromConfig($config, static function (string $message): void {
    error_log('donation-sink dashboard: ' . $message);
});
if ($accounting === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Accounting is not enabled in config.php, so there is nothing to show.\n";
    exit;
}

// ---------------------------------------------------------------------------
// Request
// ---------------------------------------------------------------------------

$tzName = (string)($dash['timezone'] ?? 'UTC');
try {
    $tz = new DateTimeZone($tzName);
} catch (Throwable $e) {
    $tz = new DateTimeZone('UTC');
    $tzName = 'UTC';
}
$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('now', $tz);

$periods = [
    'today' => 'Today',
    '7d'    => 'Last 7 days',
    '30d'   => 'Last 30 days',
    'month' => 'This month',
    'year'  => 'This year',
    'all'   => 'All time',
    'custom' => 'Custom range',
];
$period = isset($_GET['period']) && isset($periods[$_GET['period']]) ? (string)$_GET['period'] : '30d';

$buckets = ['day' => 'Day', 'month' => 'Month', 'year' => 'Year'];
$bucket = isset($_GET['bucket']) && isset($buckets[$_GET['bucket']]) ? (string)$_GET['bucket'] : 'day';

/** Read a yyyy-mm-dd from the query string, or null. */
$readDate = static function (string $key) use ($tz): ?DateTimeImmutable {
    $raw = $_GET[$key] ?? '';
    if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $tz);
    return $d === false ? null : $d;
};

$earliest = $accounting->firstDonationAt();
$allStart = $earliest !== null
    ? (new DateTimeImmutable($earliest, new DateTimeZone('UTC')))->setTimezone($tz)->setTime(0, 0)
    : $now->setTime(0, 0);

switch ($period) {
    case 'today':
        $from = $now->setTime(0, 0);
        $to = $from->modify('+1 day');
        break;
    case '7d':
        $to = $now->setTime(0, 0)->modify('+1 day');
        $from = $to->modify('-7 days');
        break;
    case 'month':
        $from = $now->setTime(0, 0)->modify('first day of this month');
        $to = $from->modify('+1 month');
        break;
    case 'year':
        $from = $now->setTime(0, 0)->setDate((int)$now->format('Y'), 1, 1);
        $to = $from->modify('+1 year');
        break;
    case 'all':
        $from = $allStart;
        $to = $now->setTime(0, 0)->modify('+1 day');
        break;
    case 'custom':
        $from = $readDate('from') ?? $now->setTime(0, 0)->modify('-30 days');
        $to = ($readDate('to') ?? $now->setTime(0, 0))->modify('+1 day');
        if ($to <= $from) {
            $to = $from->modify('+1 day');
        }
        break;
    case '30d':
    default:
        $period = '30d';
        $to = $now->setTime(0, 0)->modify('+1 day');
        $from = $to->modify('-30 days');
        break;
}

$fromUtc = $from->setTimezone($utc)->format('Y-m-d H:i:s');
$toUtc = $to->setTimezone($utc)->format('Y-m-d H:i:s');

// The series is bucketed in the display timezone, which means shifting UTC by a fixed
// offset before cutting the buckets. Computed once for the start of the range, so a
// range straddling a daylight-saving change can put an hour in the neighbouring bucket.
// At UTC (the default) the offset is zero and the question does not arise.
$offsetSeconds = $tz->getOffset(new DateTime($from->format('Y-m-d H:i:s'), $utc));

$facets = $accounting->facets();
$unit = (isset($_GET['unit']) && in_array($_GET['unit'], $facets['units'], true)) ? (string)$_GET['unit'] : null;
$mint = (isset($_GET['mint']) && in_array($_GET['mint'], $facets['mints'], true)) ? (string)$_GET['mint'] : null;

$maxProjects = (int)($dash['max_projects'] ?? 200);
$totals = $accounting->projectTotals($fromUtc, $toUtc, $unit, $mint, $maxProjects);

// ---------------------------------------------------------------------------
// CSV
// ---------------------------------------------------------------------------

if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="donations-'
        . $from->format('Ymd') . '-' . $to->modify('-1 day')->format('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    // The escape argument is passed explicitly because its default changes in PHP 8.4
    // and leaving it out is deprecated there; the value is what every version used.
    fputcsv($out, ['project', 'unit', 'donations', 'received', 'credited',
        'input_fee', 'paid_out', 'routing_fee', 'adjusted', 'outstanding'], ',', '"', '\\');
    foreach ($totals as $row) {
        fputcsv($out, [
            $row['project'] ?? '(unnamed)',
            $row['unit'],
            $row['donations'],
            $row['received'],
            $row['credited'],
            $row['input_fee'],
            $row['paid_out'],
            $row['routing_fee'],
            $row['adjusted'],
            $row['outstanding'],
        ], ',', '"', '\\');
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Rest of the page
// ---------------------------------------------------------------------------

$projectFilter = null;
if (isset($_GET['project']) && is_string($_GET['project']) && $_GET['project'] !== '') {
    $candidate = $_GET['project'];
    if ($candidate === '(unnamed)') {
        $projectFilter = '';
    } else {
        [$normalised] = Accounting::normaliseProject($candidate, []);
        $projectFilter = $normalised;
    }
}

$series = $accounting->series($fromUtc, $toUtc, $bucket, $offsetSeconds, $unit, $mint, $projectFilter);
$settlements = $accounting->settlements($fromUtc, $toUtc, $unit, $mint, 50);

/** Per-unit grand totals, so the page never adds a satoshi to a cent. */
$grand = [];
foreach ($totals as $row) {
    $u = $row['unit'];
    if (!isset($grand[$u])) {
        $grand[$u] = ['donations' => 0, 'received' => 0, 'credited' => 0, 'input_fee' => 0,
                      'paid_out' => 0, 'routing_fee' => 0, 'adjusted' => 0, 'outstanding' => 0];
    }
    foreach ($grand[$u] as $k => $_) {
        $grand[$u][$k] += $row[$k];
    }
}
ksort($grand);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Print an amount with its unit.
 *
 * Amounts are integers in the unit's own base denomination, exactly as the mint
 * expresses them: `sat` is a satoshi, `usd` and `eur` are cents. Units are never
 * added together and never converted — this sink has no exchange rate and inventing
 * one would make the whole ledger a guess.
 */
function amount(int $value, string $unit): string
{
    if ($unit === 'usd' || $unit === 'eur') {
        return number_format($value / 100, 2) . ' ' . strtoupper($unit);
    }
    return number_format($value) . ' ' . h($unit);
}

/** A link back to this page with some parameters changed. */
function selfUrl(array $overrides): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        }
    }
    return '?' . http_build_query($params);
}

$title = (string)($dash['title'] ?? 'Donations');
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
<style>
  :root { color-scheme: light dark; --fg: #16181d; --bg: #fbfbfa; --muted: #6a6f7a;
          --line: #d9dbe0; --panel: #ffffff; --accent: #1f6f4a; }
  @media (prefers-color-scheme: dark) {
    :root { --fg: #e6e7ea; --bg: #15171b; --muted: #9aa0ab; --line: #2c3037;
            --panel: #1c1f24; --accent: #6fce9f; }
  }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 24px 16px 64px; background: var(--bg); color: var(--fg);
         font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
  .wrap { max-width: 1100px; margin: 0 auto; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  h2 { font-size: 16px; margin: 32px 0 8px; font-weight: 600; }
  .sub { color: var(--muted); font-size: 13px; margin: 0 0 20px; }
  form.filters { background: var(--panel); border: 1px solid var(--line); border-radius: 8px;
                 padding: 12px; display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
  label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 3px; }
  select, input[type=date], button { font: inherit; padding: 5px 7px; border-radius: 6px;
                 border: 1px solid var(--line); background: var(--bg); color: var(--fg); }
  button { cursor: pointer; background: var(--accent); color: #fff; border-color: transparent;
           padding: 6px 14px; }
  table { border-collapse: collapse; width: 100%; margin-top: 8px; font-variant-numeric: tabular-nums; }
  th, td { text-align: right; padding: 6px 8px; border-bottom: 1px solid var(--line); white-space: nowrap; }
  th:first-child, td:first-child { text-align: left; }
  th { font-size: 12px; color: var(--muted); font-weight: 600; text-transform: uppercase;
       letter-spacing: .03em; }
  tr.total td { font-weight: 700; border-top: 2px solid var(--line); }
  .scroll { overflow-x: auto; }
  .note { color: var(--muted); font-size: 13px; max-width: 70ch; }
  .zero { color: var(--muted); }
  a { color: var(--accent); }
  code { font-size: 12.5px; }
</style>
</head>
<body>
<div class="wrap">

<h1><?= h($title) ?></h1>
<p class="sub">
  <?= h($from->format('D j M Y')) ?> to <?= h($to->modify('-1 second')->format('D j M Y')) ?>
  &middot; times in <?= h($tzName) ?>
  <?php if ($remoteUser !== null && $remoteUser !== ''): ?>&middot; signed in as <?= h($remoteUser) ?><?php endif; ?>
</p>

<form class="filters" method="get" action="">
  <div>
    <label for="period">Period</label>
    <select name="period" id="period">
      <?php foreach ($periods as $key => $label): ?>
        <option value="<?= h($key) ?>"<?= $period === $key ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="from">From</label>
    <input type="date" id="from" name="from" value="<?= h($from->format('Y-m-d')) ?>">
  </div>
  <div>
    <label for="to">To</label>
    <input type="date" id="to" name="to" value="<?= h($to->modify('-1 day')->format('Y-m-d')) ?>">
  </div>
  <div>
    <label for="unit">Unit</label>
    <select name="unit" id="unit">
      <option value="">All</option>
      <?php foreach ($facets['units'] as $u): ?>
        <option value="<?= h($u) ?>"<?= $unit === $u ? ' selected' : '' ?>><?= h($u) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="mint">Mint</label>
    <select name="mint" id="mint">
      <option value="">All</option>
      <?php foreach ($facets['mints'] as $m): ?>
        <option value="<?= h($m) ?>"<?= $mint === $m ? ' selected' : '' ?>><?= h($m) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="bucket">Group by</label>
    <select name="bucket" id="bucket">
      <?php foreach ($buckets as $key => $label): ?>
        <option value="<?= h($key) ?>"<?= $bucket === $key ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><button type="submit">Show</button></div>
  <div><a href="<?= h(selfUrl(['format' => 'csv'])) ?>">Download CSV</a></div>
</form>

<h2>Income by project</h2>
<?php if ($totals === []): ?>
  <p class="note">No donations in this period.</p>
<?php else: ?>
<div class="scroll">
<table>
  <thead>
    <tr>
      <th>Project</th><th>Unit</th><th>Donations</th>
      <th>Received</th><th>Credited</th><th>Paid out</th>
      <th>Fees</th><th>Adjusted</th><th>In wallet</th>
    </tr>
  </thead>
  <tbody>
  <?php $lastUnit = null; foreach ($totals as $row): ?>
    <?php if ($lastUnit !== null && $lastUnit !== $row['unit']): ?>
      <tr class="total">
        <td colspan="2">All projects, <?= h($lastUnit) ?></td>
        <td><?= number_format($grand[$lastUnit]['donations']) ?></td>
        <td><?= amount($grand[$lastUnit]['received'], $lastUnit) ?></td>
        <td><?= amount($grand[$lastUnit]['credited'], $lastUnit) ?></td>
        <td><?= amount($grand[$lastUnit]['paid_out'], $lastUnit) ?></td>
        <td><?= amount($grand[$lastUnit]['input_fee'] + $grand[$lastUnit]['routing_fee'], $lastUnit) ?></td>
        <td><?= amount($grand[$lastUnit]['adjusted'], $lastUnit) ?></td>
        <td><?= amount($grand[$lastUnit]['outstanding'], $lastUnit) ?></td>
      </tr>
    <?php endif; $lastUnit = $row['unit']; ?>
    <tr>
      <td><a href="<?= h(selfUrl(['project' => $row['project'] ?? '(unnamed)'])) ?>"><?= h($row['project'] ?? '(unnamed)') ?></a></td>
      <td><?= h($row['unit']) ?></td>
      <td><?= number_format($row['donations']) ?></td>
      <td><?= amount($row['received'], $row['unit']) ?></td>
      <td><?= amount($row['credited'], $row['unit']) ?></td>
      <td><?= amount($row['paid_out'], $row['unit']) ?></td>
      <td><?= amount($row['input_fee'] + $row['routing_fee'], $row['unit']) ?></td>
      <td<?= $row['adjusted'] === 0 ? ' class="zero"' : '' ?>><?= amount($row['adjusted'], $row['unit']) ?></td>
      <td><?= amount($row['outstanding'], $row['unit']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($lastUnit !== null): ?>
    <tr class="total">
      <td colspan="2">All projects, <?= h($lastUnit) ?></td>
      <td><?= number_format($grand[$lastUnit]['donations']) ?></td>
      <td><?= amount($grand[$lastUnit]['received'], $lastUnit) ?></td>
      <td><?= amount($grand[$lastUnit]['credited'], $lastUnit) ?></td>
      <td><?= amount($grand[$lastUnit]['paid_out'], $lastUnit) ?></td>
      <td><?= amount($grand[$lastUnit]['input_fee'] + $grand[$lastUnit]['routing_fee'], $lastUnit) ?></td>
      <td><?= amount($grand[$lastUnit]['adjusted'], $lastUnit) ?></td>
      <td><?= amount($grand[$lastUnit]['outstanding'], $lastUnit) ?></td>
    </tr>
  <?php endif; ?>
  </tbody>
</table>
</div>
<p class="note">
  Every column describes the same donations, the ones received in this period, and they
  add up: <strong>received</strong> is the face value of the tokens and is the income
  figure; the mint keeps an input fee on the swap, leaving <strong>credited</strong>;
  of that, <strong>paid out</strong> has reached the Lightning address, some went to
  routing <strong>fees</strong>, <strong>adjusted</strong> is money that left the wallet
  without this sink recording where it went, and <strong>in wallet</strong> is the rest,
  still here. Paid out lags: a donation received today is in the wallet until a melt
  drains it. Units are never added together; amounts are in each unit's base
  denomination, so <code>sat</code> is a satoshi and <code>usd</code>/<code>eur</code>
  are cents shown as decimals.
</p>
<?php endif; ?>

<h2>Over time<?= $projectFilter !== null ? ' &middot; ' . h($projectFilter === '' ? '(unnamed)' : $projectFilter) : '' ?></h2>
<?php if ($projectFilter !== null): ?>
  <p class="note"><a href="<?= h(selfUrl(['project' => null])) ?>">Show all projects</a></p>
<?php endif; ?>
<?php if ($series === []): ?>
  <p class="note">Nothing in this period.</p>
<?php else: ?>
<div class="scroll">
<table>
  <thead><tr><th><?= h($buckets[$bucket]) ?></th><th>Unit</th><th>Donations</th><th>Received</th><th>Credited</th></tr></thead>
  <tbody>
  <?php foreach ($series as $row): ?>
    <tr>
      <td><?= h($row['bucket']) ?></td>
      <td><?= h($row['unit']) ?></td>
      <td><?= number_format($row['donations']) ?></td>
      <td><?= amount($row['received'], $row['unit']) ?></td>
      <td><?= amount($row['credited'], $row['unit']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<h2>Payouts in this period</h2>
<?php if ($settlements === []): ?>
  <p class="note">No payouts. Donations stay in the wallet until a balance crosses its melt threshold.</p>
<?php else: ?>
<div class="scroll">
<table>
  <thead>
    <tr><th>When (UTC)</th><th>Kind</th><th>Mint</th><th>Unit</th>
        <th>Left wallet</th><th>Reached destination</th><th>Fee</th><th>Unattributed</th></tr>
  </thead>
  <tbody>
  <?php foreach ($settlements as $s): ?>
    <tr>
      <td><?= h($s['settled_at']) ?></td>
      <td><?= $s['reason'] === 'melt' ? 'Melt' : 'Adjustment' ?></td>
      <td><?= h(preg_replace('#^https?://#', '', (string)$s['mint_url'])) ?></td>
      <td><?= h($s['unit']) ?></td>
      <td><?= amount((int)$s['amount_spent'], (string)$s['unit']) ?></td>
      <td><?= amount((int)$s['amount_paid'], (string)$s['unit']) ?></td>
      <td><?= amount((int)$s['fee'], (string)$s['unit']) ?></td>
      <td<?= (int)$s['unattributed'] === 0 ? ' class="zero"' : '' ?>><?= amount((int)$s['unattributed'], (string)$s['unit']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="note">
  A melt spends whatever proofs the wallet holds for one mint and unit, so it is pooled
  across projects. It is allocated to donations oldest first. <strong>Unattributed</strong>
  is the part that drained money received before this ledger existed, or a donation whose
  row could not be written; it belongs to no project by construction.
</p>
<?php endif; ?>

</div>
</body>
</html>
