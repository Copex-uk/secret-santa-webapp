-- Secret Santa schema (MySQL / MariaDB, utf8mb4)

CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mfa_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id INT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME NULL DEFAULT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_mfa_admin (admin_id, expires_at),
    CONSTRAINT fk_mfa_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NULL,
    budget DECIMAL(8,2) NULL,
    match_emails_sent_at DATETIME NULL,
    auto_match_email TINYINT(1) NOT NULL DEFAULT 0,
    email_send_at DATETIME NOT NULL,
    reveal_at DATETIME NOT NULL,
    status ENUM('draft','assigned','emailed') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    nickname VARCHAR(100) NULL,
    gender ENUM('male','female') NULL,
    photo_path VARCHAR(255) NULL,
    invited_at DATETIME NULL,
    last_login_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    profile_complete TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_users (
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('invited','profile_complete','assigned','removed') NOT NULL DEFAULT 'invited',
    PRIMARY KEY (event_id, user_id),
    CONSTRAINT fk_eu_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_eu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unordered pair enforced in application code: user_a_id < user_b_id always.
CREATE TABLE IF NOT EXISTS relationships (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    user_a_id INT UNSIGNED NOT NULL,
    user_b_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rel_pair (event_id, user_a_id, user_b_id),
    CONSTRAINT fk_rel_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_rel_a FOREIGN KEY (user_a_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rel_b FOREIGN KEY (user_b_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_rel_order CHECK (user_a_id < user_b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    buyer_user_id INT UNSIGNED NOT NULL,
    recipient_user_id INT UNSIGNED NOT NULL,
    seen_at DATETIME NULL,
    match_email_sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assign_buyer (event_id, buyer_user_id),
    UNIQUE KEY uq_assign_recipient (event_id, recipient_user_id),
    CONSTRAINT fk_as_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_as_buyer FOREIGN KEY (buyer_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_as_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_login_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME NULL DEFAULT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_ulc_user (user_id, expires_at),
    CONSTRAINT fk_ulc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic throttle log: identifier is an email or IP, kind describes the action.
CREATE TABLE IF NOT EXISTS code_throttle (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier VARCHAR(190) NOT NULL,
    kind VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_throttle (identifier, kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_templates (
    tpl_key VARCHAR(40) PRIMARY KEY,
    subject VARCHAR(255) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
