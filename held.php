<?php
/**
 * Donations too small to swap on their own, kept until together they are worth it.
 *
 * A NUT-02 mint charges per input proof and rounds the whole swap up once:
 * ceil(sum(input_fee_ppk) / 1000). On a mint charging 100 ppk one proof costs a
 * whole satoshi, so a 1 sat donation nets nothing and the sink can only refuse it.
 * Ten of them cost that same one satoshi between them and net nine. The fee is a
 * property of the swap, not of the donation, so the answer is to stop swapping one
 * donation at a time.
 *
 * What this is not: a second wallet. A held token has not been swapped, which means
 * the donor still holds its secrets and can spend them before we do. So held money
 * is deliberately kept out of the wallet, out of getBalance(), and out of the income
 * ledger. It becomes any of those at the moment the batch swap succeeds and not one
 * step earlier. Nothing here can turn into a number on the dashboard by itself.
 *
 * The risk that buys: a donor can take back ecash that was worth exactly nothing to
 * us, because the alternative for a below-fee token is to refuse it. The risk it
 * adds: this file holds bearer secrets belonging to other people until the swap, so
 * it lives beside the wallet database and deserves the same permissions. That is why
 * it is off unless switched on.
 */

declare(strict_types=1);

namespace DonationSink;

use PDO;
use Throwable;
use Cashu\Wallet;
use Cashu\Crypto;
use Cashu\Secp256k1;
use Cashu\MintClient;
use Cashu\TokenSerializer;

final class Held
{
    /** Swap once the mint's cut of the pool is no worse than this. */
    private const DEFAULT_MAX_FEE_PERCENT = 10.0;

    /** Never let one pool grow without bound if a mint is permanently unswappable. */
    private const DEFAULT_MAX_TOKENS = 500;

    private PDO $pdo;
    /** @var callable */
    private $log;
    private float $maxFeePercent;
    private int $maxTokens;

    private function __construct(PDO $pdo, callable $log, float $maxFeePercent, int $maxTokens)
    {
        $this->pdo = $pdo;
        $this->log = $log;
        $this->maxFeePercent = $maxFeePercent;
        $this->maxTokens = $maxTokens;
    }

    /**
     * Null unless switched on and usable. Like the accounting module, a broken
     * configuration must never cost a donation: the caller falls back to refusing
     * below-fee tokens exactly as it did before.
     */
    public static function fromConfig(array $config, callable $log): ?self
    {
        $settings = $config['held_donations'] ?? [];
        if (!is_array($settings) || empty($settings['enabled'])) {
            return null;
        }
        $path = $settings['database_path'] ?? null;
        if (!is_string($path) || $path === '') {
            // Beside the wallet by default: same directory, same custody, same backup.
            $wallet = (string)($config['database_path'] ?? '');
            if ($wallet === '') {
                $log('HELD: no database_path configured; held donations are off');
                return null;
            }
            $path = dirname($wallet) . '/held.db';
        }

        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            foreach (self::schema() as $ddl) {
                $pdo->exec($ddl);
            }
            @chmod($path, 0600);
        } catch (Throwable $e) {
            $log('HELD: cannot open ' . $path . ' - ' . $e->getMessage());
            return null;
        }

        $percent = (float)($settings['max_fee_percent'] ?? self::DEFAULT_MAX_FEE_PERCENT);
        if ($percent <= 0 || $percent >= 100) {
            $percent = self::DEFAULT_MAX_FEE_PERCENT;
        }
        $maxTokens = (int)($settings['max_tokens_per_pool'] ?? self::DEFAULT_MAX_TOKENS);
        if ($maxTokens < 1) {
            $maxTokens = self::DEFAULT_MAX_TOKENS;
        }

        return new self($pdo, $log, $percent, $maxTokens);
    }

    /** @return string[] */
    public static function schema(): array
    {
        return [
            // One row per donation we could not swap on arrival. `token` is the whole
            // thing, because a swap needs the proofs and nothing smaller reconstructs
            // them.
            'CREATE TABLE IF NOT EXISTS held_tokens (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                mint_url    TEXT    NOT NULL,
                unit        TEXT    NOT NULL,
                amount      INTEGER NOT NULL,
                proof_count INTEGER NOT NULL,
                token       TEXT    NOT NULL,
                project     TEXT,
                received_at INTEGER NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS ix_held_pool ON held_tokens(mint_url, unit, id)',
            // The sender retries a token it believes undelivered until the mint says
            // its proofs are spent, which for a held token is not yet. Without this the
            // same donation is counted once a minute forever.
            'CREATE TABLE IF NOT EXISTS held_secrets (
                secret   TEXT PRIMARY KEY,
                token_id INTEGER NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS ix_held_secrets_token ON held_secrets(token_id)',
        ];
    }

    // ------------------------------------------------------------------
    // Holding
    // ------------------------------------------------------------------

    /**
     * Keep a token that cannot pay for its own swap.
     *
     * @param string[] $secrets the token's proof secrets, which identify it again
     * @return string 'held', 'duplicate', or 'full'
     */
    public function hold(string $token, string $mintUrl, string $unit, int $amount,
                         array $secrets, ?string $project): string
    {
        if ($secrets === []) {
            return 'duplicate'; // nothing to identify it by; refuse rather than double count
        }
        if ($this->countTokens($mintUrl, $unit) >= $this->maxTokens) {
            return 'full';
        }

        $this->pdo->beginTransaction();
        try {
            // Any secret already known means this donation is already here. Checked
            // inside the transaction so two simultaneous retries cannot both insert.
            $in = implode(',', array_fill(0, count($secrets), '?'));
            $st = $this->pdo->prepare("SELECT 1 FROM held_secrets WHERE secret IN ($in) LIMIT 1");
            $st->execute($secrets);
            if ($st->fetchColumn() !== false) {
                $this->pdo->rollBack();
                return 'duplicate';
            }

            $st = $this->pdo->prepare(
                'INSERT INTO held_tokens (mint_url, unit, amount, proof_count, token, project, received_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([$mintUrl, $unit, $amount, count($secrets), $token, $project, time()]);
            $id = (int)$this->pdo->lastInsertId();

            $st = $this->pdo->prepare('INSERT INTO held_secrets (secret, token_id) VALUES (?, ?)');
            foreach ($secrets as $secret) {
                $st->execute([$secret, $id]);
            }
            $this->pdo->commit();
            return 'held';
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            ($this->log)('HELD: could not hold a donation - ' . $e->getMessage());
            throw $e;
        }
    }

    public function countTokens(string $mintUrl, string $unit): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM held_tokens WHERE mint_url = ? AND unit = ?');
        $st->execute([$mintUrl, $unit]);
        return (int)$st->fetchColumn();
    }

    /** @return array{tokens:int, proofs:int, amount:int} */
    public function pool(string $mintUrl, string $unit): array
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) AS tokens, COALESCE(SUM(proof_count),0) AS proofs, COALESCE(SUM(amount),0) AS amount
               FROM held_tokens WHERE mint_url = ? AND unit = ?'
        );
        $st->execute([$mintUrl, $unit]);
        $row = $st->fetch() ?: [];
        return [
            'tokens' => (int)($row['tokens'] ?? 0),
            'proofs' => (int)($row['proofs'] ?? 0),
            'amount' => (int)($row['amount'] ?? 0),
        ];
    }

    /** @return array<int, array<string,mixed>> */
    public function rows(string $mintUrl, string $unit): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, amount, proof_count, token, project, received_at
               FROM held_tokens WHERE mint_url = ? AND unit = ? ORDER BY id ASC'
        );
        $st->execute([$mintUrl, $unit]);
        return $st->fetchAll();
    }

    /** @param int[] $ids */
    public function forget(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM held_secrets WHERE token_id IN ($in)")->execute($ids);
            $this->pdo->prepare("DELETE FROM held_tokens WHERE id IN ($in)")->execute($ids);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Batching
    // ------------------------------------------------------------------

    /**
     * Is the pool worth one swap yet?
     *
     * The fee is charged on the whole input set at once, so the question is never
     * "is this donation big enough" but "is what we are holding big enough". With a
     * 100 ppk mint and 1 sat donations that is true at exactly ten, twenty, thirty:
     * ten proofs cost one satoshi between them, eleven cost two.
     *
     * @return array{ready:bool, fee:int, amount:int, percent:float}
     */
    public function assess(Wallet $wallet, array $proofs): array
    {
        $amount = Wallet::sumProofs($proofs);
        if ($amount <= 0) {
            return ['ready' => false, 'fee' => 0, 'amount' => 0, 'percent' => 100.0];
        }
        $fee = $wallet->calculateFee($proofs);
        $percent = $fee / $amount * 100;
        return [
            'ready'   => $fee < $amount && $percent <= $this->maxFeePercent,
            'fee'     => $fee,
            'amount'  => $amount,
            'percent' => $percent,
        ];
    }

    /**
     * Swap everything held for this pool, if it is worth it.
     *
     * Returns null when nothing happened, which is the ordinary case: most donations
     * arrive into a pool that is not full yet.
     *
     * @return array{swapped:int, fee:int, credited:int, tokens:array<int,array<string,mixed>>}|null
     */
    public function settle(Wallet $wallet, string $mintUrl, string $unit): ?array
    {
        $rows = $this->rows($mintUrl, $unit);
        if (count($rows) < 2) {
            return null; // one token alone is what we already know we cannot swap
        }

        // Rebuild the proofs, remembering which donation each came from so the money
        // can be attributed after the swap.
        $proofs = [];
        $owner = [];      // secret => held_tokens.id
        $usable = [];
        foreach ($rows as $row) {
            try {
                $token = TokenSerializer::deserialize((string)$row['token']);
                foreach ($token->proofs as $proof) {
                    if (Wallet::isLockedSecret($proof->secret)) {
                        throw new \RuntimeException('locked proof');
                    }
                    $proofs[] = $proof;
                    $owner[$proof->secret] = (int)$row['id'];
                }
                $usable[(int)$row['id']] = $row;
            } catch (Throwable $e) {
                // A token we can no longer read is never going to become money.
                ($this->log)('HELD: dropping unreadable held token #' . $row['id'] . ' - ' . $e->getMessage());
                $this->forget([(int)$row['id']]);
            }
        }
        if (count($usable) < 2) {
            return null;
        }

        $wallet->resolveShortKeysetIds($proofs);

        // A held proof is one the donor could still spend, so ask the mint before
        // building a swap around it. One spent proof fails the whole swap, and
        // failing the whole swap is how a pool stops ever settling.
        [$proofs, $dropped] = $this->dropSpent($mintUrl, $proofs);
        if ($dropped !== []) {
            $gone = array_values(array_unique(array_map(fn($s) => $owner[$s] ?? 0, $dropped)));
            ($this->log)('HELD: ' . count($dropped) . ' held proof(s) were spent elsewhere; '
                . 'forgetting ' . count($gone) . ' donation(s)');
            $this->forget(array_values(array_filter($gone)));
            foreach ($gone as $id) {
                unset($usable[$id]);
            }
            $proofs = array_values(array_filter($proofs, fn($p) => !in_array($owner[$p->secret] ?? 0, $gone, true)));
        }
        if (count($usable) < 2 || $proofs === []) {
            return null;
        }

        $verdict = $this->assess($wallet, $proofs);
        if (!$verdict['ready']) {
            return null;
        }

        $net = $verdict['amount'] - $verdict['fee'];
        $swapped = $wallet->swap($proofs, Wallet::splitAmount($net));
        $credited = Wallet::sumProofs($swapped);

        // The fee was charged once, on everybody's proofs together. Share it out in
        // proportion, largest remainder first, so the parts add up to exactly what
        // the mint gave back and no donation is quietly rounded to nothing.
        $tokens = $this->share(array_values($usable), $credited);

        $this->forget(array_map(fn($t) => (int)$t['id'], $tokens));

        ($this->log)('HELD: swapped ' . count($tokens) . ' held donation(s) at ' . $mintUrl
            . ' - ' . $verdict['amount'] . ' ' . $unit . ' in, fee ' . $verdict['fee']
            . ', ' . $credited . ' credited');

        return ['swapped' => $verdict['amount'], 'fee' => $verdict['fee'],
                'credited' => $credited, 'tokens' => $tokens];
    }

    /**
     * Ask the mint which of these proofs are still unspent (NUT-07).
     *
     * A mint that cannot answer leaves the pool alone rather than guessing: swapping
     * on a guess is what turns "one donor took their sat back" into "nothing in this
     * pool ever settles".
     *
     * @param \Cashu\Proof[] $proofs
     * @return array{0: \Cashu\Proof[], 1: string[]} [keep, dropped secrets]
     */
    private function dropSpent(string $mintUrl, array $proofs): array
    {
        $Ys = [];
        foreach ($proofs as $proof) {
            $Ys[] = bin2hex(Secp256k1::compressPoint(Crypto::hashToCurve($proof->secret)));
        }
        $response = (new MintClient(rtrim($mintUrl, '/')))->post('checkstate', ['Ys' => $Ys]);
        $states = $response['states'] ?? null;
        if (!is_array($states) || count($states) !== count($proofs)) {
            throw new \RuntimeException('mint returned an incomplete proof state response');
        }

        $keep = [];
        $dropped = [];
        foreach ($proofs as $i => $proof) {
            // Only an explicit UNSPENT is good enough. PENDING and anything unfamiliar
            // are treated as unusable now and re-examined next time.
            if (($states[$i]['state'] ?? '') === 'UNSPENT') {
                $keep[] = $proof;
            } else {
                $dropped[] = $proof->secret;
            }
        }
        return [$keep, $dropped];
    }

    /**
     * Largest remainder: every share is floor()ed, then the pennies go to whoever was
     * rounded down hardest, so sum(shares) === $total exactly.
     *
     * @param array<int, array<string,mixed>> $rows
     * @return array<int, array<string,mixed>> the same rows with a 'credited' key
     */
    private function share(array $rows, int $total): array
    {
        $gross = array_sum(array_map(fn($r) => (int)$r['amount'], $rows));
        if ($gross <= 0) {
            return array_map(fn($r) => $r + ['credited' => 0], $rows);
        }

        $out = [];
        $remainders = [];
        $given = 0;
        foreach ($rows as $i => $row) {
            $exact = (int)$row['amount'] * $total / $gross;
            $floor = (int)floor($exact);
            $out[$i] = $row + ['credited' => $floor];
            $remainders[$i] = $exact - $floor;
            $given += $floor;
        }
        arsort($remainders);
        foreach (array_keys($remainders) as $i) {
            if ($given >= $total) {
                break;
            }
            $out[$i]['credited']++;
            $given++;
        }
        return array_values($out);
    }
}
