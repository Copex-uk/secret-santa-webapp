-- Secret Santa schema migrations
--
-- Safe to run the whole file at any time: every statement is idempotent
-- (IF NOT EXISTS), so already-applied changes are skipped rather than
-- aborting the batch. Order matters where a column is placed AFTER another.
--
-- Docker:  cat private/schema-migrations.sql | docker compose exec -T db \
--            mariadb -u ssanta -pYOURDBPASS ssanta
--
-- v1.1: gender + default avatar support (run once on databases created before this)
ALTER TABLE users ADD COLUMN IF NOT EXISTS gender ENUM('male','female') NULL AFTER nickname;

-- v1.2: per-event gift budget shown on the reveal card
ALTER TABLE events ADD COLUMN IF NOT EXISTS budget DECIMAL(8,2) NULL AFTER name;

-- v1.3: admin-editable HTML email templates
CREATE TABLE IF NOT EXISTS email_templates (
    tpl_key VARCHAR(40) PRIMARY KEY,
    subject VARCHAR(255) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v1.4: track when the invitation email was last sent
ALTER TABLE users ADD COLUMN IF NOT EXISTS invited_at DATETIME NULL AFTER photo_path;

-- v1.5: track when each user last logged in
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER invited_at;

-- v1.5: records when match cards were emailed for an event
ALTER TABLE events ADD COLUMN IF NOT EXISTS match_emails_sent_at DATETIME NULL AFTER budget;

-- v1.6: match cards sent automatically once everyone has seen their reveal
ALTER TABLE assignments ADD COLUMN IF NOT EXISTS seen_at DATETIME NULL AFTER recipient_user_id;
ALTER TABLE events ADD COLUMN IF NOT EXISTS auto_match_email TINYINT(1) NOT NULL DEFAULT 0 AFTER match_emails_sent_at;

-- v1.7: per-person record of which match cards were emailed
ALTER TABLE assignments ADD COLUMN IF NOT EXISTS match_email_sent_at DATETIME NULL AFTER seen_at;
