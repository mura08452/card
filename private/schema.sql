CREATE TABLE IF NOT EXISTS accounts (
    id VARCHAR(36) PRIMARY KEY,
    auth_id VARCHAR(128) NOT NULL UNIQUE,
    profile MEDIUMTEXT NOT NULL,
    state MEDIUMTEXT NOT NULL,
    revision BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS tickets (
    hash CHAR(64) PRIMARY KEY,
    owner_id VARCHAR(36) NOT NULL,
    expires BIGINT NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS exchange_requests (
    id VARCHAR(36) PRIMARY KEY,
    ticket_hash CHAR(64) NOT NULL,
    owner_id VARCHAR(36) NOT NULL,
    requester_id VARCHAR(36) NOT NULL,
    status VARCHAR(16) NOT NULL,
    created VARCHAR(40) NOT NULL,
    UNIQUE KEY request_ticket_user (ticket_hash, requester_id),
    FOREIGN KEY (ticket_hash) REFERENCES tickets(hash),
    FOREIGN KEY (owner_id) REFERENCES accounts(id),
    FOREIGN KEY (requester_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS connections (
    id VARCHAR(36) PRIMARY KEY,
    left_id VARCHAR(36) NOT NULL,
    right_id VARCHAR(36) NOT NULL,
    met_at VARCHAR(40) NOT NULL,
    left_name TEXT NOT NULL,
    right_name TEXT NOT NULL,
    UNIQUE KEY connection_pair (left_id, right_id),
    FOREIGN KEY (left_id) REFERENCES accounts(id),
    FOREIGN KEY (right_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS identities (
    subject VARCHAR(128) PRIMARY KEY,
    auth_id VARCHAR(128) NOT NULL,
    provider VARCHAR(16) NOT NULL,
    FOREIGN KEY (auth_id) REFERENCES accounts(auth_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS devices (
    hash CHAR(64) PRIMARY KEY,
    auth_id VARCHAR(128) NOT NULL,
    expires BIGINT NOT NULL,
    INDEX devices_expiry (expires),
    FOREIGN KEY (auth_id) REFERENCES accounts(auth_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS card_storage_version (
    version INTEGER PRIMARY KEY
) ENGINE=InnoDB;
