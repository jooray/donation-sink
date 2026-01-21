# Donation Sink

A PHP-based Cashu token donation receiver that accepts donations, swaps tokens to prevent re-spending, and automatically melts to a Lightning address when balances reach configured thresholds.

## Features

- **Accept Cashu Tokens**: Receive donations via simple POST requests
- **Auto-Swap**: Automatically swaps incoming tokens to prevent re-spending
- **Multi-Mint Support**: Trusts and accepts tokens from any mint
- **Multi-Currency**: Supports sat, usd, eur, and other currency units
- **Auto-Melt**: Automatically converts to Lightning when per-mint balances reach thresholds
- **Resilient**: If melting fails, tokens are safely stored for retry on next donation

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
