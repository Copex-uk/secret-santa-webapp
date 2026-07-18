-- v1.1: gender + default avatar support (run once on databases created before this)
ALTER TABLE users ADD COLUMN gender ENUM('male','female') NULL AFTER nickname;

-- v1.2: per-event gift budget shown on the reveal card
ALTER TABLE events ADD COLUMN budget DECIMAL(8,2) NULL AFTER name;

-- v1.3: admin-editable HTML email templates
CREATE TABLE IF NOT EXISTS email_templates (
    tpl_key VARCHAR(40) PRIMARY KEY,
    subject VARCHAR(255) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v1.4: track when the invitation email was last sent
ALTER TABLE users ADD COLUMN invited_at DATETIME NULL AFTER photo_path;
