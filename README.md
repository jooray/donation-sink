# Donation Sink

A PHP-based Cashu token donation receiver that accepts donations, swaps tokens to prevent re-spending, and automatically melts to a Lightning address when balances reach configured thresholds.

<!-- jooray-links:start -->
### More from me

**Related projects**

- [cashupayserver](https://github.com/jooray/cashupayserver): BTCPay-compatible Lightning payments through Cashu, on ordinary PHP hosting
- [cashu-wallet-php](https://github.com/jooray/cashu-wallet-php): a Cashu wallet library in PHP
- [mint-discovery](https://github.com/jooray/mint-discovery): Cashu mint discovery library
- [btcpay-greenfield-test](https://github.com/jooray/btcpay-greenfield-test): a minimal page for testing BTCPay Greenfield API integrations

**Full project showcase:** Part of [CashuPayServer](https://juraj.bednar.io/showcase/#PAY-01) in my project showcase, or [all my projects](https://juraj.bednar.io/showcase/).

I write about building things on [my blog](https://juraj.bednar.io/en/blog-en/). I also wrote a cypherpunk novel, [Tamers of Entropy](https://tamersofentropy.net/), and there is a [trailer](https://tamersofentropy.net/#trailer).
<!-- jooray-links:end -->

## Features

- **Accept Cashu Tokens**: Receive donations via simple POST requests
- **Auto-Swap**: Automatically swaps incoming tokens to prevent re-spending
- **Multi-Mint Support**: Trusts and accepts tokens from any mint
- **Multi-Currency**: Supports sat, usd, eur, and other currency units
- **Auto-Melt**: Automatically converts to Lightning when per-mint balances reach thresholds
- **Resilient**: If melting fails, tokens are safely stored for retry on next donation
- **Per-project accounting** (optional): a donation can say who it is for, and a dashboard reports income per project, period, mint and unit. Off by default; see [Per-project accounting](#per-project-accounting-optional).

## How It Works

1. User sends a POST request with a Cashu token
2. System deserializes the token to identify mint and currency unit
3. Creates/uses a wallet instance for that specific mint+unit combination
4. Swaps the token (preventing re-spending) and stores it in the database
5. Checks if the balance for that mint+unit exceeds the configured threshold
6. If threshold reached, automatically melts entire balance to configured Lightning address
7. Returns success response

## Requirements

- PHP 7.4 or higher
- SQLite3 extension (usually enabled by default)
- Web server (Apache, Nginx, etc.)
- Write permissions for database and log file directories

Only if you turn on per-project accounting, which is off by default:

- MariaDB 10.5+ or MySQL 8+, and PHP's `pdo_mysql` extension

## Installation

### 1. Clone the Repository

```bash
git clone --recursive https://github.com/jooray/donation-sink.git
cd donation-sink
```

Note: The `--recursive` flag is required to clone the `cashu-wallet-php` submodule.

If you already cloned without `--recursive`, run:

```bash
git submodule init
git submodule update
```

### 2. Create Configuration

Copy the example configuration:

```bash
cp config.php.example config.php
```

### 3. Generate Seed Phrase

Generate a 12-word BIP39 mnemonic seed phrase using one of these methods:

**Python** (recommended):
```bash
pip install mnemonic
python -c "from mnemonic import Mnemonic; print(Mnemonic('english').generate(128))"
```

**Node.js**:
```bash
npm install bip39
node -e "console.log(require('bip39').generateMnemonic())"
```

**Electrum wallet**: Create a new wallet and copy the seed phrase.

**IMPORTANT**: Save this seed phrase securely! Anyone with access to it can access all donations.

Copy the generated seed phrase to your `config.php` file (not the example file!).

### 4. Configure Settings

Edit `config.php` and set:

- `database_path`: Path to SQLite database (MUST be outside webroot)
- `seed_phrase`: The seed phrase you generated
- `lightning_address`: Your Lightning address for receiving melted funds
- `melt_thresholds`: Balance thresholds for each currency unit
- `log_path`: Path to log file (MUST be writable by web server)

Example configuration:

```php
return [
    'database_path' => '/var/lib/donation-sink/donations.db',
    'seed_phrase' => 'your twelve word seed phrase goes here exactly as generated',
    'lightning_address' => 'donations@getalby.com',
    'melt_thresholds' => [
        'sat' => 500,   // Auto-melt at 500 sats
        'usd' => 100,   // Auto-melt at 100 cents ($1.00)
        'eur' => 100,   // Auto-melt at 100 cents (€1.00)
    ],
    'log_path' => '/var/log/donation-sink/donations.log',
    'default_melt_threshold' => 100,
];
```

### 5. Create Required Directories

Ensure the database and log directories exist and are writable:

```bash
# Create database directory
sudo mkdir -p /var/lib/donation-sink
sudo chown www-data:www-data /var/lib/donation-sink

# Create log directory
sudo mkdir -p /var/log/donation-sink
sudo chown www-data:www-data /var/log/donation-sink
```

Note: Replace `www-data` with your web server user if different.

### 6. Test the Installation

Test with a small Cashu token:

```bash
curl -X POST https://donations.example.com/donation-sink.php \
  -H "Content-Type: application/json" \
  -d '{"token":"cashuBo2F0gaJhaU..."}'
```

Expected response:

```json
{
  "status": "success",
  "message": "thank you"
}
```

## Usage

### Sending Donations

Send a POST request to `donation-sink.php` with the token parameter.

#### JSON Format (Recommended)

```bash
curl -X POST https://donations.example.com/donation-sink.php \
  -H "Content-Type: application/json" \
  -d '{"token":"cashuBo2F0gaJhaU..."}'
```

#### Form-Encoded Format

```bash
curl -X POST https://donations.example.com/donation-sink.php \
  -d "token=cashuBo2F0gaJhaU..."
```

A donation can also name the project it is for, with an optional `project`
parameter. See [Per-project accounting](#per-project-accounting-optional).

### Response Format

Success:

```json
{
  "status": "success",
  "message": "thank you"
}
```

Error:

```json
{
  "status": "error",
  "message": "error description"
}
```

## Donations too small to swap (optional)

A NUT-02 mint charges a fee **per input proof** and rounds the whole swap up once:
`ceil(sum(input_fee_ppk) / 1000)`. On a mint charging 100 ppk a single proof costs a
whole satoshi, so a 1 sat donation nets its recipient nothing and the sink can only
refuse it. Ten of them cost that same one satoshi between them and net nine. The fee
belongs to the swap, not to the donation, so the answer is to stop swapping one
donation at a time.

With `held_donations.enabled` on, a token that cannot pay for its own swap is kept
instead of refused, and the pool is swapped in a single operation as soon as the
mint's share is no worse than `max_fee_percent`. At 100 ppk and 1 sat donations that
is exactly ten, twenty, thirty proofs: ten cost one satoshi between them, eleven cost
two.

**A held token is not income and not yours.** It has not been swapped, so the donor
still holds its secrets and can spend them first. Held money is therefore kept out of
the wallet, out of `getBalance()` and out of the ledger until the batch swap succeeds,
and the mint is asked which proofs are still unspent before the swap is built, so one
donor taking their ecash back cannot stop a pool settling. The risk you take is on
money that was worth exactly nothing to you; the risk you add is that `held.db` holds
other people's bearer secrets until the swap, so give it the care you give the wallet.

The sink answers **200** for a held token, on purpose. A sender does not have to take
our word for it: CashuPayServer, for one, keeps its copy and asks the mint whether the
proofs are spent, so it simply retries until the batch settles, and the same secrets
are recognised rather than counted twice. A new status code would have told that to
every sender that understood it and broken every sender that did not.

```php
'held_donations' => [
    'enabled'             => true,
    'database_path'       => null,   // defaults to held.db beside the wallet
    'max_fee_percent'     => 10,
    'max_tokens_per_pool' => 500,
],
```

Run `php tests/held_donations.php` to watch it happen against a mint that really
charges 100 ppk.

## Per-project accounting (optional)

One sink can receive for many projects. A donation says who it is for, and a
dashboard reports what each project earned over a period.

**It is optional and off by default.** Clone this repository, leave the
`accounting` and `dashboard` blocks out of `config.php`, and you get exactly the
behaviour described above: the wallet in SQLite, no second database, no schema,
no MariaDB. Turning it on adds a database that holds a ledger and nothing else.
**No Cashu proof ever leaves SQLite.** Deleting the whole accounting database
loses bookkeeping, never money.

### Naming a project

Add `project` to the request, as JSON, as a form field, or in the query string:

```bash
curl -X POST https://donations.example.com/donation-sink.php \
  -H "Content-Type: application/json" \
  -d '{"token":"cashuBo2F0gaJhaU...","project":"example-project"}'

curl -X POST https://donations.example.com/donation-sink.php \
  -d "token=cashuBo2F0gaJhaU...&project=example-project"

curl -X POST "https://donations.example.com/donation-sink.php?project=example-project" \
  -d "token=cashuBo2F0gaJhaU..."
```

The response repeats the project back, so the caller can check the attribution
landed:

```json
{"status": "success", "message": "thank you", "project": "example-project"}
```

**A new project needs no setup.** There is no list to edit and nothing to
restart: the first donation that names a project creates it. A donation with no
project keeps working and is recorded as unnamed.

A name is lowercased and must be 1 to 64 characters of ASCII letters, digits,
dot, dash or underscore, starting with a letter or a digit. Anything else is
refused with a 400 **before the token is touched**, so a rejected name costs the
donor nothing: no mint has been contacted, no proof has been swapped, and the
token can be sent again with a name that works.

That narrow shape is the whole defence against a project name being anything
other than a label. It cannot contain a slash, a dot-dot, a NUL, a quote or a
newline, so there is nothing to traverse, nothing to inject and nothing that can
forge a line in the log. Names reach SQL only as bound parameters and reach the
dashboard only HTML-escaped; the character rule is a second lock on a door that
is already shut.

Nothing stops somebody inventing project names, but **inventing one costs
money**: a project row is written only after a token has been swapped
successfully, so each new name needs a real donation the mint accepted. The
number of projects is therefore bounded by the number of paid donations. If you
would still rather have a fixed list, set `project.allowed` in `config.php`; the
default empty list accepts any well-formed name, which is the point of the
feature.

### What the numbers mean

Income is attributed **at receipt**. When a token is swapped we know the amount,
the mint, the unit and the project, so the donation row written at that moment is
the ledger, and it never changes afterwards. That is what "project X earned N
between A and B" means, and it is the number to quote.

Paying out is a different question, because **melting is pooled**. The sink melts
the whole balance of one mint and unit at once, and Cashu proofs carry no project
label. Tagging proofs would mean writing into the wallet's own database, which
holds bearer money and is the one thing this feature will not touch. So a melt is
recorded as its own event and then **allocated to the donations it drained,
oldest first**. Each donation therefore carries how much of it has been settled,
and the dashboard can show:

| Column | What it is |
|---|---|
| **Received** | Face value of the tokens. The income figure. |
| **Credited** | What survived the mint's input fee on the swap. |
| **Paid out** | The project's share of melts that actually drained it, as it reached the Lightning address. |
| **Fees** | The mint's input fee plus the project's share of routing fees. |
| **Adjusted** | Money that left the wallet without this sink recording where it went. |
| **In wallet** | The rest, still held as proofs. |

They reconcile exactly, for every project and every unit:

```
received = fees(input) + credited
credited = paid out + fees(routing) + adjusted + in wallet
```

**Paid out lags, and is meant to.** A donation received today sits in the wallet
until a balance crosses its melt threshold, so it shows as "in wallet", not as
"paid out". Do not read "paid out" as this period's income; read "received".

Two honest edges, both visible on the page rather than hidden:

- A melt can drain more than the ledger knows about, because the wallet held
  money from before accounting was switched on. The excess is allocated to no
  donation at all and shows as **unattributed** in the payouts table, so the
  allocations of a melt always sum to what left the wallet.
- The wallet can spend outside this script: a melt recovered after a lost
  response, a manual payment, a proof the mint declared spent. So at the start
  of every donation, once any interrupted melt has been resolved and before this
  one is swapped, the ledger is compared with the wallet's real balance and the
  difference is booked as an **adjustment**, which pays nobody. Without it "in
  wallet" would slowly become fiction. That is also the only moment the two are
  comparable, which is why it happens there rather than at the end.

### Currency

**Units are never added together and never converted.** This sink has no
exchange rate and inventing one would make the ledger a guess. Every total is
per unit, and amounts are integers in that unit's own base denomination exactly
as the mint expresses them: `sat` is a satoshi, `usd` and `eur` are cents (shown
with a decimal point on the dashboard, stored as integers). A project that
received both sats and cents has two rows and no combined figure.

### The database

Accounting lives in its own MariaDB database, created either by `schema.sql` or
by the sink itself on the first donation. Five tables: `ds_projects`,
`ds_donations` (the ledger), `ds_settlements` (melts and adjustments),
`ds_settlement_allocations` (how each settlement was spread over donations) and
`ds_meta`. The indexes are chosen so "income for project X between A and B" is a
range scan of `ix_donations_project (project_id, unit, received_at)`.

All timestamps are UTC. The dashboard can display another timezone, but UTC is
the only setting with no daylight-saving caveat.

**A donation is never lost to an accounting failure.** Every database call is
wrapped: a database that is down, full, misconfigured or not created yet costs a
ledger row and writes the reason to the log, and the donation is still accepted,
swapped and melted as normal. The log line before the failed write carries the
whole record, so it can be replayed by hand.

### Money that arrived before you switched this on

Accounting only counts what it saw. If the sink has been running for a while, the
wallet holds real money no ledger row explains, and it surfaces as **unattributed**
the first time a melt drains it. That is honest, and useless when you know perfectly
well whose money it is.

`bin/opening-balance.php` writes one donation row for the balance a pool already
holds, marked `opening`, so it is attributed to a project and FIFO spends it first,
which is also the truth: it arrived before anything the ledger did see. The dashboard
shows it next to that project's **Received** figure as `incl. N opening`, so nobody
reads it as income earned on the day you ran the tool.

It reads the wallet and never writes to it. Run it **before the first donation** under
accounting, and only once per mint and unit; it refuses a second claim, because a
second opening balance would invent money.

```bash
php bin/opening-balance.php                                          # which pools hold anything
php bin/opening-balance.php --mint=https://mint.example --project=myproject
php bin/opening-balance.php --mint=https://mint.example --project=myproject --commit
```

Nothing is written without `--commit`.

### Enabling it

1. Create the database and a user for it:

   ```sql
   CREATE DATABASE donation_sink CHARACTER SET utf8mb4;
   CREATE USER 'donation_sink'@'localhost' IDENTIFIED BY 'a long random password';
   GRANT SELECT, INSERT, UPDATE, DELETE ON donation_sink.* TO 'donation_sink'@'localhost';
   FLUSH PRIVILEGES;
   ```

2. Create the schema as an administrator, so the web user never holds `CREATE`:

   ```bash
   mysql donation_sink < schema.sql
   ```

3. Fill in the `accounting` block in `config.php` and set
   `'auto_migrate' => false`, since the schema now exists.

Skipping steps 1 and 2 also works: grant the user `CREATE` as well, leave
`auto_migrate` on, and the first donation builds the schema itself.

## The dashboard

`dashboard.php` shows income per project, over day, month or year buckets,
filtered by period, mint and unit, with a CSV download. It is read-only: it never
touches the wallet, never melts anything and never writes to the accounting
database.

Set `dashboard.enabled` to `true` in `config.php` to switch it on. It is off by
default so that deploying the tree cannot expose income before the web server is
configured.

### Protecting the dashboard

**The dashboard has no login of its own.** Authentication belongs to the web
server, as HTTP basic auth.

As a fuse against the obvious accident, the page refuses to render unless the web
server tells it who the visitor is (`REMOTE_USER`, or an `Authorization` header
PHP turns into `PHP_AUTH_USER`). That check confirms the protection is switched
on; it is not itself a lock, and `dashboard.require_web_auth` turns it off if you
protect the page some other way.

Make the password file first, outside the document root:

```bash
htpasswd -c /etc/nginx/donation-dashboard.htpasswd yourname   # -c only for the first user
```

**On nginx** there is no `.htaccess`: nginx does not read those files and never
has. The configuration goes in the vhost, and it has a trap worth stating plainly.
PHP is normally handled by a **regex** location like `location ~ \.php$`, and in
nginx a regex location beats a prefix one. A block written as
`location ^~ /donation-sink/dashboard.php` would therefore win the match, take
PHP handling with it, and serve the dashboard's **source** instead of running it.
Use an exact match and repeat the PHP handling inside it:

Whatever web server you use, **serve the two entry points and nothing else**. This
directory holds a `config.php` with a seed phrase in it, a vendored wallet library and
a CLI tool. Deny by default and name what is allowed, rather than listing what to
hide: a list of things to hide is wrong the moment a file is added, which is exactly
what happened here when `held.php` arrived after the list was written.

```nginx
# Ahead of the vhost's `location ~ \.php$`: nginx takes the first matching regex
# location in file order, so below it every file here would be handed to PHP.
location ~ ^/donation-sink/(?!donation-sink\.php$|dashboard\.php$) {
    return 404;
}
```

```nginx
location = /donation-sink/dashboard.php {
    auth_basic           "Donations";
    auth_basic_user_file /etc/nginx/donation-dashboard.htpasswd;

    # Same PHP handling as the vhost's `location ~ \.php$`, because this
    # location replaces it rather than adding to it.
    try_files      $uri =404;
    fastcgi_pass   unix:/run/php-fpm/www.sock;
    fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include        fastcgi_params;

    # nginx does not pass the authenticated user to FastCGI by default.
    fastcgi_param  REMOTE_USER $remote_user;
}
```

Check it before trusting it:

```bash
curl -si https://donations.example.com/donation-sink/dashboard.php | head -1   # expect 401
curl -si -u yourname:… https://donations.example.com/donation-sink/dashboard.php | head -1  # expect 200
```

A `200` on the first command means the location is not matching. A `500` telling
you the dashboard is unprotected on the second means the `REMOTE_USER` line is
missing.

**On Apache**, copy `dashboard.htaccess.example` to `.htaccess`, point
`AuthUserFile` at the password file, and make sure the vhost allows
`AllowOverride AuthConfig`. `.htaccess` is gitignored.

### Keeping config.php out of reach

`config.php` holds the seed phrase, and the seed phrase is the money. If the
application tree lives inside the document root, one broken PHP handler is all
that stands between the seed and a plain-text download. Either keep the tree
outside the webroot, or point the sink at a configuration file that is:

```nginx
fastcgi_param DONATION_SINK_CONFIG /home/you/.donation-sink/config.php;
```

`donation-sink.php` and `dashboard.php` both honour `DONATION_SINK_CONFIG` and
fall back to `config.php` next to the code.

## Tests

```bash
php tests/accounting-test.php
```

Runs without a database and checks the parts that need none. To exercise the
ledger, point it at a **throwaway** database, which it wipes before it starts:

```bash
DONATION_SINK_TEST_DSN='mysql:host=127.0.0.1;dbname=ds_test;charset=utf8mb4' \
DONATION_SINK_TEST_USER=root DONATION_SINK_TEST_PASS= \
php tests/accounting-test.php
```

Never point it at the database a live sink is using.

## How Balances Work

The system maintains separate balances for each **mint+currency** combination:

- `https://mint1.example.com` + `sat` → separate balance
- `https://mint1.example.com` + `usd` → separate balance
- `https://mint2.example.com` + `sat` → separate balance

Each combination is tracked independently in the shared SQLite database. When a donation arrives:

1. The system identifies which mint+unit combination it belongs to
2. Swaps the token to that wallet
3. Checks if that specific combination's balance exceeds its threshold
4. If yes, melts the entire balance for that combination

## Auto-Melt Behavior

When a mint+unit balance reaches or exceeds its configured threshold:

1. **Success**: Funds are melted to Lightning, balance resets to zero (or change amount)
2. **Failure**: Error is logged, tokens remain in wallet, retry on next donation

Melt failures don't affect donation acceptance - the donation is still safely stored.

## Logging

All events are logged to the configured log file.

## Security Considerations

### Critical Security Rules

1. **Database Location**: MUST be outside webroot to prevent direct access
2. **Seed Phrase Protection**:
   - Never commit `config.php` to version control
   - Store backups securely offline
   - Anyone with the seed can access all donations
3. **File Permissions**:
   - `config.php` should be readable only by web server user
   - Database and log directories should not be web-accessible

### Trust Model

This system **trusts all mints** by design (donation model). It will accept tokens from any mint without verification. 

### Recommended Permissions

```bash
# Configuration file
chmod 600 config.php
chown www-data:www-data config.php

# Database directory
chmod 750 /var/lib/donation-sink
chown www-data:www-data /var/lib/donation-sink

# Log directory
chmod 750 /var/log/donation-sink
chown www-data:www-data /var/log/donation-sink
```

## Database Management

### Viewing Balances

The SQLite database can be queried directly:

```bash
sqlite3 /var/lib/donation-sink/donations.db

# View all unspent proofs
SELECT wallet_id, amount, state FROM proofs WHERE state = 'UNSPENT';

# View total balance per wallet
SELECT wallet_id, SUM(amount) as total FROM proofs WHERE state = 'UNSPENT' GROUP BY wallet_id;
```

### Backup

Regular backups are recommended:

```bash
# Backup database
cp /var/lib/donation-sink/donations.db /backup/donations-$(date +%Y%m%d).db

# Backup logs
cp /var/log/donation-sink/donations.log /backup/donations-$(date +%Y%m%d).log
```

## Development

### Project Structure

```
donation-sink/
├── cashu-wallet-php/          # Submodule: Cashu wallet library
├── config.php                 # Your configuration (gitignored)
├── config.php.example         # Configuration template
├── donation-sink.php          # Main endpoint
├── accounting.php             # Optional per-project ledger (inert unless enabled)
├── dashboard.php              # Optional read-only dashboard
├── schema.sql                 # Accounting schema, generated from accounting.php
├── dashboard.htaccess.example # Apache basic auth for the dashboard
├── tests/
│   └── accounting-test.php    # Tests for the accounting layer
├── .gitignore                 # Git ignore rules
└── README.md                  # This file
```

### Testing

Test with small amounts first:

1. Generate a test token at a testnet mint
2. POST it to your endpoint
3. Check logs for successful processing
4. Verify database contains the proofs
5. Send more tokens to trigger auto-melt
6. Verify melt completed and Lightning payment received

## Acknowledgments

Built with my [cashu-wallet-php](https://github.com/jooray/cashu-wallet-php).

## Support and value4value

If you like this project, I would appreciate if you contributed time, talent or treasure.

Time and talent can be used in testing it out, fixing bugs or submitting pull requests.

Treasure can be [sent back through here](https://juraj.bednar.io/en/support-me/).
