-- Donation sink: accounting schema (MariaDB 10.5+ / MySQL 8+).
--
-- This file is generated from Accounting::schema() in accounting.php, which is what
-- the sink itself runs when accounting.auto_migrate is on. Run it by hand instead if
-- you would rather the web user never held CREATE:
--
--   mysql donation_sink < schema.sql
--
-- Then set accounting.auto_migrate to false in config.php.
--
-- The wallet is NOT in here. Cashu proofs stay in SQLite at database_path; this
-- database holds only the ledger, and losing all of it loses no money.

CREATE TABLE IF NOT EXISTS ds_meta (
    k VARCHAR(64) NOT NULL,
    v VARCHAR(255) NOT NULL,
    PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE IF NOT EXISTS ds_projects (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(64) NOT NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_projects_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;

CREATE TABLE IF NOT EXISTS ds_donations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    received_at     DATETIME NOT NULL,
    project_id      INT UNSIGNED NULL,
    mint_url        VARCHAR(255) NOT NULL,
    unit            VARCHAR(16) NOT NULL,
    amount_token    BIGINT UNSIGNED NOT NULL,
    amount_credited BIGINT UNSIGNED NOT NULL,
    settled_amount  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_donations_project (project_id, unit, received_at),
    KEY ix_donations_period (received_at, project_id),
    KEY ix_donations_pool (mint_url, unit, settled_amount, received_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ds_settlements (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ds_settlement_allocations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ds_meta (k, v) VALUES ('schema_version', '1')
    ON DUPLICATE KEY UPDATE v = VALUES(v);
