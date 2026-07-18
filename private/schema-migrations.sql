-- v1.1: gender + default avatar support (run once on databases created before this)
ALTER TABLE users ADD COLUMN gender ENUM('male','female') NULL AFTER nickname;

-- v1.2: per-event gift budget shown on the reveal card
ALTER TABLE events ADD COLUMN budget DECIMAL(8,2) NULL AFTER name;
