<?php
declare(strict_types=1);

/**
 * Optional per-project accounting for the donation sink.
 *
 * This file is inert until `accounting.enabled` is true in config.php. Without it
 * the sink behaves exactly as it always has: SQLite for the wallet, no second
 * database, no schema, no extra requirement on the host.
 *
 * Two rules govern everything here:
 *
 *  1. ACCOUNTING NEVER TOUCHES THE WALLET. The SQLite file holds live Cashu proofs,
 *     which are bearer money. Nothing in this file opens it, reads it or writes it.
 *     The only wallet fact it consumes is a balance integer handed to reconcile(),
 *     which the caller has already read for its own purposes.
 *
 *  2. ACCOUNTING NEVER FAILS A DONATION. Every public method runs inside guard(),
 *     which swallows every Throwable, writes it to the sink's log and returns null.
 *     A database that is down, full, misconfigured or simply not created yet costs
 *     us a ledger row, never a donation. The log line carries the full record so it
 *     can be replayed by hand.
 *
 * See README.md, "Per-project accounting", for what the numbers mean.
 */

namespace DonationSink;

use PDO;
use PDOException;
use Throwable;

/**
 * Where config.php lives.
 *
 * DONATION_SINK_CONFIG lets the configuration (which holds the seed phrase) live
 * outside the webroot even when the code does not. On nginx:
 *
 *     fastcgi_param DONATION_SINK_CONFIG /home/you/.donation-sink/config.php;
 */
function config_path(string $baseDir): string
{
    $override = getenv('DONATION_SINK_CONFIG');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return $baseDir . '/config.php';
}

/**
 * Strip anything that could forge a log line out of untrusted text.
 */
function log_safe(string $value, int $max = 200): string
{
    $clean = preg_replace('/[\x00-\x1f\x7f]/', ' ', $value);
    if ($clean === null) {
        $clean = '';
    }
    return mb_substr($clean, 0, $max);
}

final class Accounting
{
    /** Bumped when the schema below changes; recorded in ds_meta. */
    public const SCHEMA_VERSION = 2;

    /**
     * A donation row that is not a donation: the balance the wallet already held when
     * accounting was switched on. Someone donated that money, we just were not
     * counting yet, so it belongs to a project even though no POST created it.
     */
    public const SOURCE_OPENING = 'opening';

    /** A settlement is either a Lightning melt we performed, or a correction. */
    public const REASON_MELT = 'melt';
    public const REASON_RECONCILE = 'reconcile';

    /** @var array */
    private $settings;

    /** @var callable */
    private $logger;

    /** @var PDO|null Opened lazily: a disabled or unused sink never connects. */
    private $conn = null;

    /** @var bool */
    private $migrated = false;

    private function __construct(array $settings, callable $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /**
     * Build the accounting recorder, or null when accounting is switched off.
     *
     * Returning null rather than a no-op object is deliberate: the call sites read
     * `$accounting !== null && $accounting->...`, so the disabled path costs nothing
     * and is obvious in a diff.
     */
    public static function fromConfig(array $config, callable $logger): ?self
    {
        $settings = $config['accounting'] ?? [];
        if (!is_array($settings) || empty($settings['enabled'])) {
            return null;
        }
        if (empty($settings['dsn']) || !is_string($settings['dsn'])) {
            $logger('ACCOUNTING ERROR: accounting.enabled is true but accounting.dsn is missing; accounting is off');
            return null;
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $logger('ACCOUNTING ERROR: PHP has no pdo_mysql driver; accounting is off');
            return null;
        }
        return new self($settings, $logger);
    }

    // ------------------------------------------------------------------
    // Project names
    // ------------------------------------------------------------------

    /**
     * Turn whatever arrived on the wire into a project name, or explain why not.
     *
     * A project needs no configuration to exist: the first donation that names it
     * creates it. So the only gate is the shape of the string, and it is deliberately
     * narrow — lowercase ASCII letters, digits, dot, dash and underscore, first
     * character alphanumeric, 64 bytes at most. That rules out path traversal, NUL,
     * quotes, newlines that could forge a log line, and anything that would need
     * escaping anywhere. Names are lowercased so `NsiteClay` and `nsiteclay` are one
     * project rather than two.
     *
     * @param mixed $raw
     * @return array{0: ?string, 1: ?string} [name or null, error message or null]
     */
    public static function normaliseProject($raw, array $config): array
    {
        $settings = $config['project'] ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }

        $max = (int)($settings['max_length'] ?? 64);
        if ($max < 1 || $max > 64) {
            $max = 64;
        }

        if ($raw === null || $raw === '') {
            $default = $settings['default'] ?? null;
            if (is_string($default) && trim($default) !== '') {
                return self::normaliseProject($default, ['project' => ['max_length' => $max]]);
            }
            return [null, null]; // unnamed donation: exactly today's behaviour
        }

        if (!is_string($raw)) {
            return [null, 'Project must be a string'];
        }

        $name = strtolower(trim($raw));
        if ($name === '') {
            return [null, null];
        }
        if (strlen($name) > $max) {
            return [null, "Project name is longer than $max characters"];
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $name)) {
            return [null, 'Project name may contain only letters, digits, dot, dash and underscore, and must start with a letter or a digit'];
        }

        $allowed = $settings['allowed'] ?? [];
        if (is_array($allowed) && $allowed !== []) {
            $known = array_map(static function ($p) {
                return strtolower(trim((string)$p));
            }, $allowed);
            if (!in_array($name, $known, true)) {
                return [null, 'Unknown project'];
            }
        }

        return [$name, null];
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /**
     * Record one accepted donation. Called after the swap succeeded, so the money
     * is already ours and the amounts are final.
     *
     * $amountToken    face value of the token the donor sent
     * $amountCredited what actually landed in the wallet (the mint keeps an input fee)
     *
     * @return int|null the donation row id, or null if it could not be written
     */
    public function recordDonation(
        string $mintUrl,
        string $unit,
        int $amountToken,
        int $amountCredited,
        ?string $project,
        ?string $source = null
    ): ?int {
        $describe = 'project=' . ($project ?? '-') . ' mint=' . log_safe($mintUrl) . ' unit=' . log_safe($unit)
            . ' token=' . $amountToken . ' credited=' . $amountCredited
            . ($source === null ? '' : ' source=' . log_safe($source));

        return $this->guard('record donation (' . $describe . ')', function () use ($mintUrl, $unit, $amountToken, $amountCredited, $project, $source) {
            $pdo = $this->pdo();
            $projectId = $project === null ? null : $this->projectId($project);

            $st = $pdo->prepare(
                'INSERT INTO ds_donations
                    (received_at, project_id, mint_url, unit, amount_token, amount_credited, settled_amount, source)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?)'
            );
            $st->execute([
                gmdate('Y-m-d H:i:s'),
                $projectId,
                self::clip($mintUrl, 255),
                self::clip($unit, 16),
                max(0, $amountToken),
                max(0, $amountCredited),
                $source === null ? null : self::clip($source, 16),
            ]);

            return (int)$pdo->lastInsertId();
        });
    }

    /**
     * Claim the balance the wallet already held for a project.
     *
     * Switching accounting on mid-life leaves real money in the wallet that no ledger
     * row explains. Left alone it surfaces as `unattributed` the first time a melt
     * drains it, which is honest but useless when you know perfectly well whose money
     * it is. This writes one donation row for it, so it is attributed, and FIFO spends
     * it first, which is also the truth: it arrived before everything else.
     *
     * It refuses to run twice for the same mint and unit, because a second opening
     * balance would invent money. Returns the row id, or null if one already exists
     * or the write failed.
     */
    public function recordOpeningBalance(string $mintUrl, string $unit, int $amount, string $project): ?int
    {
        if ($amount <= 0) {
            return null;
        }
        if ($this->hasOpeningBalance($mintUrl, $unit)) {
            $this->log('ACCOUNTING: refusing a second opening balance for '
                . log_safe($mintUrl) . ' ' . log_safe($unit));
            return null;
        }
        return $this->recordDonation($mintUrl, $unit, $amount, $amount, $project, self::SOURCE_OPENING);
    }

    /** True when an opening balance has already been claimed for this mint and unit. */
    public function hasOpeningBalance(string $mintUrl, string $unit): bool
    {
        return (bool)$this->guard('opening balance check', function () use ($mintUrl, $unit) {
            $st = $this->pdo()->prepare(
                'SELECT 1 FROM ds_donations WHERE mint_url = ? AND unit = ? AND source = ? LIMIT 1'
            );
            $st->execute([self::clip($mintUrl, 255), self::clip($unit, 16), self::SOURCE_OPENING]);
            return $st->fetchColumn() !== false;
        });
    }

    /** Donation rows recorded so far for a mint and unit, opening balance included. */
    public function donationCount(string $mintUrl, string $unit): int
    {
        return (int)($this->guard('donation count', function () use ($mintUrl, $unit) {
            $st = $this->pdo()->prepare('SELECT COUNT(*) FROM ds_donations WHERE mint_url = ? AND unit = ?');
            $st->execute([self::clip($mintUrl, 255), self::clip($unit, 16)]);
            return (int)$st->fetchColumn();
        }) ?? 0);
    }

    /**
     * Record a completed melt and spread it over the donations it drained.
     *
     * The melt is pooled by construction — the wallet spends whatever proofs it holds
     * for this mint and unit, and those proofs carry no project label. So the payout
     * is allocated to donations oldest first (FIFO) until the spent amount is used up.
     * Anything left over belonged to money that predates the ledger and is recorded
     * against no donation at all, so that sum(allocations) always equals what left
     * the wallet.
     *
     * $amountPaid is the invoice amount that reached the destination; $fee is what the
     * mint and the Lightning route took on top. They sum to what actually left.
     */
    public function recordMelt(
        string $mintUrl,
        string $unit,
        int $amountPaid,
        int $fee,
        ?string $quoteId,
        ?string $preimage,
        ?string $destination
    ): void {
        if ($fee < 0) {
            $this->log('ACCOUNTING WARN: melt reported a negative fee (' . $fee . '); treating it as zero');
            $fee = 0;
        }
        $spent = max(0, $amountPaid) + $fee;
        if ($spent <= 0) {
            return;
        }

        $this->guard('record melt (mint=' . log_safe($mintUrl) . ' unit=' . log_safe($unit)
            . ' paid=' . $amountPaid . ' fee=' . $fee . ' quote=' . log_safe((string)$quoteId, 80) . ')',
            function () use ($mintUrl, $unit, $amountPaid, $fee, $spent, $quoteId, $preimage, $destination) {
                $pdo = $this->pdo();
                $pdo->beginTransaction();
                try {
                    $st = $pdo->prepare(
                        'INSERT INTO ds_settlements
                            (settled_at, reason, mint_url, unit, amount_spent, amount_paid, fee,
                             destination, quote_id, preimage)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $st->execute([
                        gmdate('Y-m-d H:i:s'),
                        self::REASON_MELT,
                        self::clip($mintUrl, 255),
                        self::clip($unit, 16),
                        $spent,
                        max(0, $amountPaid),
                        $fee,
                        $destination === null ? null : self::clip($destination, 255),
                        $quoteId === null ? null : self::clip($quoteId, 128),
                        $preimage === null ? null : self::clip($preimage, 160),
                    ]);
                    $settlementId = (int)$pdo->lastInsertId();

                    $this->allocate($settlementId, $mintUrl, $unit, $spent, max(0, $amountPaid));
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
                return null;
            });
    }

    /**
     * Keep the ledger honest about money that left the wallet without us seeing it.
     *
     * The wallet can spend outside this code path: a melt recovered by
     * recoverPendingMelts(), a manual payment, a proof the mint declared spent. When
     * that happens the ledger still thinks it holds funds the wallet no longer has.
     * Rather than let "outstanding" quietly become fiction, the difference is booked
     * as a settlement with reason `reconcile`: nothing was paid to the destination, so
     * it shows on the dashboard as an adjustment, not as income delivered.
     *
     * The opposite direction (the wallet holds more than the ledger knows about) needs
     * no correction — that is simply money received before accounting was switched on,
     * and it lands in the unattributed bucket the first time a melt drains it.
     */
    public function reconcile(string $mintUrl, string $unit, int $walletBalance): void
    {
        $this->guard('reconcile (mint=' . log_safe($mintUrl) . ' unit=' . log_safe($unit)
            . ' balance=' . $walletBalance . ')',
            function () use ($mintUrl, $unit, $walletBalance) {
                $pdo = $this->pdo();
                $st = $pdo->prepare(
                    'SELECT COALESCE(SUM(amount_credited - settled_amount), 0)
                       FROM ds_donations
                      WHERE mint_url = ? AND unit = ? AND settled_amount < amount_credited'
                );
                $st->execute([self::clip($mintUrl, 255), self::clip($unit, 16)]);
                $outstanding = (int)$st->fetchColumn();

                $missing = $outstanding - max(0, $walletBalance);
                if ($missing <= 0) {
                    return null;
                }

                $this->log('ACCOUNTING: ledger held ' . $outstanding . ' ' . log_safe($unit)
                    . ' for ' . log_safe($mintUrl) . ' but the wallet holds ' . $walletBalance
                    . '; booking ' . $missing . ' as an adjustment');

                $pdo->beginTransaction();
                try {
                    $st = $pdo->prepare(
                        'INSERT INTO ds_settlements
                            (settled_at, reason, mint_url, unit, amount_spent, amount_paid, fee,
                             destination, quote_id, preimage)
                         VALUES (?, ?, ?, ?, ?, 0, ?, NULL, NULL, NULL)'
                    );
                    $st->execute([
                        gmdate('Y-m-d H:i:s'),
                        self::REASON_RECONCILE,
                        self::clip($mintUrl, 255),
                        self::clip($unit, 16),
                        $missing,
                        $missing,
                    ]);
                    $this->allocate((int)$pdo->lastInsertId(), $mintUrl, $unit, $missing, 0);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
                return null;
            });
    }

    /**
     * Spread $gross over the unsettled donations of one mint+unit, oldest first.
     *
     * $paid of that gross reached the destination and the rest was fee, so each
     * donation's share is split the same way. The split uses largest-remainder
     * rounding, which keeps sum(net) exactly equal to $paid rather than a rounding
     * error away from it. Must be called inside a transaction.
     */
    private function allocate(int $settlementId, string $mintUrl, string $unit, int $gross, int $paid): void
    {
        $pdo = $this->pdo();

        $st = $pdo->prepare(
            'SELECT id, project_id, (amount_credited - settled_amount) AS remaining
               FROM ds_donations
              WHERE mint_url = ? AND unit = ? AND settled_amount < amount_credited
              ORDER BY received_at ASC, id ASC
              FOR UPDATE'
        );
        $st->execute([self::clip($mintUrl, 255), self::clip($unit, 16)]);

        $shares = [];   // [donation_id|null, project_id|null, gross]
        $left = $gross;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, (int)$row['remaining']);
            if ($take <= 0) {
                continue;
            }
            $shares[] = [(int)$row['id'], $row['project_id'] === null ? null : (int)$row['project_id'], $take];
            $left -= $take;
        }
        if ($left > 0) {
            // Money the ledger never saw arrive: from before accounting was enabled,
            // or from a donation whose row could not be written.
            $shares[] = [null, null, $left];
        }

        $nets = self::splitProportionally(array_map(static function ($s) {
            return $s[2];
        }, $shares), $paid);

        $insert = $pdo->prepare(
            'INSERT INTO ds_settlement_allocations
                (settlement_id, donation_id, project_id, gross, net, fee)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $bump = $pdo->prepare('UPDATE ds_donations SET settled_amount = settled_amount + ? WHERE id = ?');

        foreach ($shares as $i => $share) {
            [$donationId, $projectId, $shareGross] = $share;
            $net = $nets[$i];
            $insert->execute([$settlementId, $donationId, $projectId, $shareGross, $net, $shareGross - $net]);
            if ($donationId !== null) {
                $bump->execute([$shareGross, $donationId]);
            }
        }
    }

    /**
     * Split $total across $weights so the parts sum to exactly $total.
     *
     * @param int[] $weights
     * @return int[]
     */
    public static function splitProportionally(array $weights, int $total): array
    {
        $sum = array_sum($weights);
        $n = count($weights);
        if ($n === 0) {
            return [];
        }
        if ($sum <= 0 || $total <= 0) {
            return array_fill(0, $n, 0);
        }

        $parts = [];
        $remainders = [];
        $assigned = 0;
        foreach ($weights as $i => $w) {
            $exact = ($w * $total) / $sum;
            $parts[$i] = (int)floor($exact);
            $remainders[$i] = $exact - $parts[$i];
            $assigned += $parts[$i];
        }

        // Hand the leftover units to the largest remainders, ties to the earlier entry.
        $order = array_keys($remainders);
        usort($order, static function ($a, $b) use ($remainders) {
            if ($remainders[$a] === $remainders[$b]) {
                return $a <=> $b;
            }
            return $remainders[$b] <=> $remainders[$a];
        });
        $leftover = $total - $assigned;
        foreach ($order as $i) {
            if ($leftover <= 0) {
                break;
            }
            $parts[$i]++;
            $leftover--;
        }

        ksort($parts);
        return array_values($parts);
    }

    /** Find or create the project row. A project needs no setup: naming it makes it. */
    private function projectId(string $name): ?int
    {
        $pdo = $this->pdo();
        $now = gmdate('Y-m-d H:i:s');

        $find = $pdo->prepare('SELECT id FROM ds_projects WHERE name = ?');
        $find->execute([$name]);
        $id = $find->fetchColumn();

        if ($id !== false && $id !== null) {
            $pdo->prepare('UPDATE ds_projects SET last_seen_at = ? WHERE id = ?')->execute([$now, (int)$id]);
            return (int)$id;
        }

        $pdo->prepare(
            'INSERT INTO ds_projects (name, first_seen_at, last_seen_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)'
        )->execute([$name, $now, $now]);

        $find->execute([$name]);
        $id = $find->fetchColumn();
        return ($id === false || $id === null) ? null : (int)$id;
    }

    // ------------------------------------------------------------------
    // Reading (the dashboard)
    // ------------------------------------------------------------------

    /**
     * Per project and unit, what happened to the donations received in [$from, $to).
     *
     * Every column describes the same set of donations, so they decompose exactly:
     *
     *     received = input_fee + credited
     *     credited = paid_out + routing_fee + adjusted + outstanding
     *
     * "paid_out" is the donation's share of melts that actually drained it, which is
     * why it lags: money received today and still sitting in the wallet is
     * outstanding, not paid out. Settlements are dated by the donation they drained,
     * not by when the melt happened — see settlements() for the other view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function projectTotals(string $fromUtc, string $toUtc, ?string $unit, ?string $mint, int $limit = 200): array
    {
        return $this->guard('project totals', function () use ($fromUtc, $toUtc, $unit, $mint, $limit) {
            $pdo = $this->pdo();
            [$where, $args] = self::donationFilter($fromUtc, $toUtc, $unit, $mint);

            $st = $pdo->prepare(
                'SELECT COALESCE(p.name, \'\') AS project, d.unit,
                        COUNT(*)                                        AS donations,
                        SUM(d.amount_token)                             AS received,
                        SUM(d.amount_credited)                          AS credited,
                        SUM(d.amount_token - d.amount_credited)         AS input_fee,
                        SUM(d.amount_credited - d.settled_amount)       AS outstanding,
                        SUM(CASE WHEN d.source = \'opening\' THEN d.amount_credited ELSE 0 END) AS opening
                   FROM ds_donations d
                   LEFT JOIN ds_projects p ON p.id = d.project_id
                  WHERE ' . $where . '
                  GROUP BY project, d.unit'
            );
            $st->execute($args);
            $rows = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $key = $r['project'] . "\0" . $r['unit'];
                $rows[$key] = [
                    'project'     => $r['project'] === '' ? null : $r['project'],
                    'unit'        => $r['unit'],
                    'donations'   => (int)$r['donations'],
                    'received'    => (int)$r['received'],
                    'credited'    => (int)$r['credited'],
                    'input_fee'   => (int)$r['input_fee'],
                    'outstanding' => (int)$r['outstanding'],
                    'opening'     => (int)$r['opening'],
                    'paid_out'    => 0,
                    'routing_fee' => 0,
                    'adjusted'    => 0,
                ];
            }

            $st = $pdo->prepare(
                'SELECT COALESCE(p.name, \'\') AS project, d.unit,
                        SUM(CASE WHEN s.reason = \'melt\' THEN a.net   ELSE 0 END) AS paid_out,
                        SUM(CASE WHEN s.reason = \'melt\' THEN a.fee   ELSE 0 END) AS routing_fee,
                        SUM(CASE WHEN s.reason <> \'melt\' THEN a.gross ELSE 0 END) AS adjusted
                   FROM ds_settlement_allocations a
                   JOIN ds_donations d   ON d.id = a.donation_id
                   JOIN ds_settlements s ON s.id = a.settlement_id
                   LEFT JOIN ds_projects p ON p.id = d.project_id
                  WHERE ' . $where . '
                  GROUP BY project, d.unit'
            );
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $key = $r['project'] . "\0" . $r['unit'];
                if (!isset($rows[$key])) {
                    continue;
                }
                $rows[$key]['paid_out'] = (int)$r['paid_out'];
                $rows[$key]['routing_fee'] = (int)$r['routing_fee'];
                $rows[$key]['adjusted'] = (int)$r['adjusted'];
            }

            $out = array_values($rows);
            usort($out, static function ($a, $b) {
                if ($a['unit'] !== $b['unit']) {
                    return strcmp($a['unit'], $b['unit']);
                }
                return $b['credited'] <=> $a['credited'];
            });
            return $limit > 0 ? array_slice($out, 0, $limit) : $out;
        }) ?? [];
    }

    /**
     * Income over time, bucketed by day, month or year.
     *
     * $offsetSeconds shifts UTC to the display timezone before the bucket is cut. It
     * is a fixed offset computed once for the queried range, so a range that straddles
     * a daylight-saving change can put an hour in the neighbouring bucket. Leave the
     * dashboard timezone at UTC and the question does not arise.
     *
     * @return array<int, array<string, mixed>>
     */
    public function series(
        string $fromUtc,
        string $toUtc,
        string $bucket,
        int $offsetSeconds,
        ?string $unit,
        ?string $mint,
        ?string $project
    ): array {
        $formats = [
            'day'   => '%Y-%m-%d',
            'month' => '%Y-%m',
            'year'  => '%Y',
        ];
        $format = $formats[$bucket] ?? $formats['day'];

        return $this->guard('series', function () use ($fromUtc, $toUtc, $format, $offsetSeconds, $unit, $mint, $project) {
            $pdo = $this->pdo();
            [$where, $args] = self::donationFilter($fromUtc, $toUtc, $unit, $mint);
            if ($project !== null) {
                if ($project === '') {
                    $where .= ' AND d.project_id IS NULL';
                } else {
                    $where .= ' AND d.project_id = (SELECT id FROM ds_projects WHERE name = ?)';
                    $args[] = $project;
                }
            }

            $st = $pdo->prepare(
                'SELECT DATE_FORMAT(d.received_at + INTERVAL ' . (int)$offsetSeconds . ' SECOND, ?) AS bucket,
                        d.unit,
                        COUNT(*)               AS donations,
                        SUM(d.amount_token)    AS received,
                        SUM(d.amount_credited) AS credited
                   FROM ds_donations d
                  WHERE ' . $where . '
                  GROUP BY bucket, d.unit
                  ORDER BY bucket ASC, d.unit ASC'
            );
            $st->execute(array_merge([$format], $args));

            return array_map(static function ($r) {
                return [
                    'bucket'    => $r['bucket'],
                    'unit'      => $r['unit'],
                    'donations' => (int)$r['donations'],
                    'received'  => (int)$r['received'],
                    'credited'  => (int)$r['credited'],
                ];
            }, $st->fetchAll(PDO::FETCH_ASSOC));
        }) ?? [];
    }

    /** Melts and adjustments by their own timestamp, newest first. */
    public function settlements(string $fromUtc, string $toUtc, ?string $unit, ?string $mint, int $limit = 50): array
    {
        return $this->guard('settlements', function () use ($fromUtc, $toUtc, $unit, $mint, $limit) {
            $pdo = $this->pdo();
            $where = 's.settled_at >= ? AND s.settled_at < ?';
            $args = [$fromUtc, $toUtc];
            if ($unit !== null) {
                $where .= ' AND s.unit = ?';
                $args[] = $unit;
            }
            if ($mint !== null) {
                $where .= ' AND s.mint_url = ?';
                $args[] = $mint;
            }

            $st = $pdo->prepare(
                'SELECT s.id, s.settled_at, s.reason, s.mint_url, s.unit, s.amount_spent,
                        s.amount_paid, s.fee, s.destination,
                        COALESCE((SELECT SUM(a.gross) FROM ds_settlement_allocations a
                                   WHERE a.settlement_id = s.id AND a.donation_id IS NULL), 0) AS unattributed
                   FROM ds_settlements s
                  WHERE ' . $where . '
                  ORDER BY s.settled_at DESC, s.id DESC
                  LIMIT ' . max(1, min(500, $limit))
            );
            $st->execute($args);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }) ?? [];
    }

    /** Distinct units and mints seen, for the dashboard's filters. */
    public function facets(): array
    {
        return $this->guard('facets', function () {
            $pdo = $this->pdo();
            return [
                'units' => $pdo->query('SELECT DISTINCT unit FROM ds_donations ORDER BY unit')->fetchAll(PDO::FETCH_COLUMN),
                'mints' => $pdo->query('SELECT DISTINCT mint_url FROM ds_donations ORDER BY mint_url')->fetchAll(PDO::FETCH_COLUMN),
            ];
        }) ?? ['units' => [], 'mints' => []];
    }

    /** Earliest donation on record, so "all time" can start somewhere real. */
    public function firstDonationAt(): ?string
    {
        $value = $this->guard('first donation', function () {
            return $this->pdo()->query('SELECT MIN(received_at) FROM ds_donations')->fetchColumn();
        });
        return (is_string($value) && $value !== '') ? $value : null;
    }

    /** @return array{0: string, 1: array} */
    private static function donationFilter(string $fromUtc, string $toUtc, ?string $unit, ?string $mint): array
    {
        $where = 'd.received_at >= ? AND d.received_at < ?';
        $args = [$fromUtc, $toUtc];
        if ($unit !== null) {
            $where .= ' AND d.unit = ?';
            $args[] = $unit;
        }
        if ($mint !== null) {
            $where .= ' AND d.mint_url = ?';
            $args[] = $mint;
        }
        return [$where, $args];
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    /**
     * Run $fn, and make sure nothing it does can escape into the donation path.
     *
     * The one recovery it attempts is creating the schema: if a statement fails
     * because a table is not there and auto_migrate is on, the schema is created and
     * the work retried exactly once. That keeps DDL out of the steady-state request
     * path entirely while still letting a fresh install work with no manual step.
     *
     * @return mixed|null
     */
    private function guard(string $what, callable $fn)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $this->abandonTransaction();

            if ($this->canMigrateFor($e)) {
                try {
                    $this->migrate();
                    return $fn();
                } catch (Throwable $second) {
                    $this->abandonTransaction();
                    $this->log('ACCOUNTING ERROR: ' . $what . ' failed after creating the schema - ' . self::describe($second));
                    return null;
                }
            }

            $this->log('ACCOUNTING ERROR: ' . $what . ' failed - ' . self::describe($e));
            return null;
        }
    }

    private function abandonTransaction(): void
    {
        try {
            if ($this->conn !== null && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
        } catch (Throwable $ignored) {
            // Nothing useful to do; the connection is being discarded anyway.
        }
    }

    private function canMigrateFor(Throwable $e): bool
    {
        if ($this->migrated || empty($this->settings['auto_migrate'] ?? true)) {
            return false;
        }
        // SQLSTATE 42S02 is "base table or view not found".
        return $e instanceof PDOException && (string)$e->getCode() === '42S02';
    }

    private function pdo(): PDO
    {
        if ($this->conn !== null) {
            return $this->conn;
        }

        $timeout = (int)($this->settings['timeout'] ?? 3);
        if ($timeout < 1 || $timeout > 30) {
            $timeout = 3;
        }

        $conn = new PDO(
            (string)$this->settings['dsn'],
            isset($this->settings['username']) ? (string)$this->settings['username'] : null,
            isset($this->settings['password']) ? (string)$this->settings['password'] : null,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => $timeout,
            ]
        );

        // A donation must not wait on somebody else's row lock. Five seconds is far
        // longer than any statement here needs and far shorter than a donor's patience.
        try {
            $conn->exec('SET SESSION innodb_lock_wait_timeout = 5');
        } catch (Throwable $ignored) {
        }

        $this->conn = $conn;
        return $conn;
    }

    /**
     * Create the schema if it is not there.
     *
     * Deliberately plain: InnoDB, no foreign keys, no triggers, no views. Integrity
     * is maintained in one place (allocate()) inside a transaction, and keeping the
     * DDL boring means the grant the sink needs can be four verbs rather than ten.
     */
    public function migrate(): void
    {
        $pdo = $this->pdo();
        foreach (self::schema() as $ddl) {
            $pdo->exec($ddl);
        }
        $pdo->prepare(
            'INSERT INTO ds_meta (k, v) VALUES (\'schema_version\', ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v)'
        )->execute([(string)self::SCHEMA_VERSION]);
        $this->migrated = true;
    }

    /**
     * The whole schema, as statements. Also dumped verbatim to schema.sql, which is
     * what an operator who prefers to create it by hand should run.
     *
     * @return string[]
     */
    public static function schema(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS ds_meta (
                k VARCHAR(64) NOT NULL,
                v VARCHAR(255) NOT NULL,
                PRIMARY KEY (k)
            ) ENGINE=InnoDB DEFAULT CHARSET=ascii",

            // Project names are restricted to ASCII by normaliseProject(), so an ascii
            // column with a binary collation is both the smallest index and the one
            // that cannot surprise us with locale-dependent equality.
            "CREATE TABLE IF NOT EXISTS ds_projects (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name          VARCHAR(64) NOT NULL,
                first_seen_at DATETIME NOT NULL,
                last_seen_at  DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_projects_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin",

            // One row per accepted donation. This is the income ledger and the only
            // thing in here that is authoritative; everything else is derived.
            "CREATE TABLE IF NOT EXISTS ds_donations (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                received_at     DATETIME NOT NULL,
                project_id      INT UNSIGNED NULL,
                mint_url        VARCHAR(255) NOT NULL,
                unit            VARCHAR(16) NOT NULL,
                amount_token    BIGINT UNSIGNED NOT NULL,
                amount_credited BIGINT UNSIGNED NOT NULL,
                settled_amount  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                -- NULL for an ordinary donation. 'opening' for the balance the wallet
                -- already held when accounting started, so the dashboard can show it
                -- as an opening balance rather than as income earned on that date.
                source          VARCHAR(16) NULL,
                PRIMARY KEY (id),
                KEY ix_donations_project (project_id, unit, received_at),
                KEY ix_donations_period (received_at, project_id),
                KEY ix_donations_pool (mint_url, unit, settled_amount, received_at, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // One row per melt we performed, plus corrections for money that left the
            // wallet some other way.
            "CREATE TABLE IF NOT EXISTS ds_settlements (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                settled_at   DATETIME NOT NULL,
                reason       VARCHAR(16) NOT NULL,
                mint_url     VARCHAR(255) NOT NULL,
                unit         VARCHAR(16) NOT NULL,
                amount_spent BIGINT UNSIGNED NOT NULL,
                amount_paid  BIGINT UNSIGNED NOT NULL,
                fee          BIGINT UNSIGNED NOT NULL,
                destination  VARCHAR(255) NULL,
                quote_id     VARCHAR(128) NULL,
                preimage     VARCHAR(160) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_settlements_quote (quote_id),
                KEY ix_settlements_pool (mint_url, unit, settled_at),
                KEY ix_settlements_time (settled_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // How each settlement was spread over the donations it drained.
            "CREATE TABLE IF NOT EXISTS ds_settlement_allocations (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                settlement_id BIGINT UNSIGNED NOT NULL,
                donation_id   BIGINT UNSIGNED NULL,
                project_id    INT UNSIGNED NULL,
                gross         BIGINT UNSIGNED NOT NULL,
                net           BIGINT UNSIGNED NOT NULL,
                fee           BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                KEY ix_alloc_settlement (settlement_id),
                KEY ix_alloc_donation (donation_id),
                KEY ix_alloc_project (project_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }

    private static function describe(Throwable $e): string
    {
        return log_safe(get_class($e) . ': ' . $e->getMessage(), 300);
    }

    private static function clip(string $value, int $max): string
    {
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
