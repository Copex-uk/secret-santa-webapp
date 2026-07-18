# 🎅 Secret Santa Web App

Self-hosted Secret Santa organiser for families and small groups.
Plain PHP 8 + MySQL/MariaDB — no frameworks, no Composer — deployable to a
Docker host or classic cPanel shared hosting from the same codebase.

**Image:** `ghcr.io/copex-uk/secret-santa-webapp` (built automatically from
version tags by GitHub Actions).

## Features

Passwordless email-code login for participants and email MFA for admins.
Festive themed UI with a slot-machine reveal card that spins (blurred)
through all participants before landing on your giftee — or on a "Please
Standby" card before the reveal moment. Selfie upload from file, webcam or
phone camera, with server-side downscaling and EXIF/GPS stripping. Gendered
default avatars so newly invited users only need to type their name to be
draw-ready, and automatic invitation emails when users are added. Admin tools for events (create / edit / delete / per-event
budget), participants, couple exclusions, and a masked assignments view that
requires password re-entry to unmask. Assignment generation is a
backtracking perfect-matching solver that honours exclusions and fails
atomically when constraints are impossible. A cron worker emails everyone
"log in to see who you got" at the scheduled time — the recipient is never
put in an email.

Security throughout: PDO prepared statements, CSRF tokens on every POST,
hashed single-use login codes with expiry and throttling, hardened session
cookies, upload validation by real MIME type, and reveal masking enforced in
SQL rather than in the UI.

## Privacy — what this repo does and doesn't contain

The repository contains **code and artwork only**. All personal data lives
outside git and is ignored via `.gitignore`:

- `.env` — DB and SMTP credentials (commit `.env.example` only)
- `private/config/` — the wizard-written runtime config
- `public_html/uploads/` — participants' selfie photos

Names, emails, photos and assignments are stored in your own database and
Docker volumes on your own host. Nothing phones home.

## Quick start with Docker (recommended)

```bash
cp .env.example .env        # set DB_PASS / DB_ROOT_PASS (and SMTP if you like)
docker compose up -d --build
```

Then open `http://your-host:8080/admin/login.php`. The database (and SMTP,
if you filled it in) is configured **entirely from `.env`** — compose passes
the values to the app and cron containers, the wizard detects them, hides
those sections, and only asks for the initial admin email + password. If you
leave the `SMTP_*` vars empty, the wizard asks for SMTP in the browser
instead. Either way, never enter `localhost` as a hostname — from inside a
container that points at the container itself (the classic
`SQLSTATE[HY000] [2002] No such file or directory` error); the DB host is
`db` and it's already wired up for you.

Environment variables always win over the wizard-written config file, so
rotating DB or SMTP credentials is just an `.env` edit + `docker compose up -d`.

Three services come up: `app` (PHP 8.3 + Apache), `db` (MariaDB 11, data in
the `dbdata` volume), and `cron` (same image, runs the reveal-email script
every 5 minutes — no host crontab needed). The wizard-written config and the
photo uploads live in the `config` and `uploads` volumes, so
`docker compose down && up` keeps everything.

Behind a reverse proxy (nginx/Traefik/NPM), terminate TLS there and forward
`X-Forwarded-Proto: https` — the app detects it and marks session cookies
Secure. A GitHub Actions workflow is included
(`.github/workflows/docker-publish.yml`) that publishes
`ghcr.io/copex-uk/secret-santa-webapp` on version tags (`git tag v1.0.0 &&
git push origin v1.0.0`). For a Docker host that should pull the prebuilt
image rather than build from source, use `docker-compose.deploy.yml`.

## Folder structure

```
/home/YOURUSER/
├── public_html/                  ← webroot (upload the contents of public_html/ here)
│   ├── index.php                 redirects to the right place
│   ├── login.php                 user login: email input
│   ├── code.php                  user login: 6-digit code input
│   ├── logout.php
│   ├── assets/style.css
│   ├── uploads/                  user photos (.htaccess blocks script execution)
│   │   ├── .htaccess
│   │   └── index.html
│   ├── user/
│   │   ├── dashboard.php         setup status / post-reveal "buying for" message
│   │   └── profile.php           name + nickname + selfie upload
│   └── admin/
│       ├── login.php             setup wizard + password login + email MFA
│       ├── dashboard.php
│       ├── users.php             add by email, list, edit, photo re-upload
│       ├── events.php            create events (email_send_at / reveal_at)
│       ├── relationships.php     mark couple pairs per event
│       ├── assign.php            generate assignments
│       ├── assignments.php       masked view; unmask via password re-check
│       └── logout.php
└── private/                      ← OUTSIDE the webroot (upload as sibling of public_html)
    ├── config/                   app.php is written here by the setup wizard
    ├── schema.sql                CREATE TABLE statements (run by the wizard)
    ├── lib/
    │   ├── bootstrap.php         config loading + hardened session + includes
    │   ├── db.php                PDO connection + schema runner
    │   ├── csrf.php              CSRF token / verify
    │   ├── auth.php              admin + user session helpers, unmask flag
    │   ├── codes.php             login/MFA codes, hashing, expiry, throttling
    │   ├── mailer.php            dependency-free SMTP client (465 SSL / 587 STARTTLS)
    │   ├── upload.php            photo validation (MIME+ext+size), random names
    │   ├── assignment.php        backtracking perfect-matching generator
    │   ├── events.php            "current event" selection helper
    │   └── layout.php            shared page chrome + flash messages
    └── cron/
        └── send_reveal_emails.php  cron script (CLI only)
```

The pages locate `private/` relative to the webroot's parent directory
(`dirname(webroot)/private`), which matches the standard cPanel layout of
`/home/YOURUSER/public_html` + `/home/YOURUSER/private`.

## Installation on cPanel

1. **Create the database** in cPanel → *MySQL Databases*: create a database,
   a DB user, and grant the user ALL privileges on the database. Note the
   full (prefixed) names, e.g. `myuser_ssanta` / `myuser_ssuser`.

2. **Upload files** with File Manager or SFTP. The app is layout-agnostic —
   each page walks up the directory tree until it finds `private/`, and all
   links/redirects auto-prefix the subfolder, so both of these work:

   *Layout A — app at the domain root:*
   - contents of `public_html/` → your webroot (`/home/YOURUSER/public_html/`)
   - `private/` → `/home/YOURUSER/private/`
   - visit `https://yourdomain/admin/login.php`

   *Layout B — app in a subfolder:*
   - contents of `public_html/` → e.g. `/home/YOURUSER/public_html/santa/`
   - `private/` → `/home/YOURUSER/private/` (account root is fine)
   - visit `https://yourdomain/santa/admin/login.php` — note the subfolder
     in the URL; `/admin/login.php` alone will 404

   Never place `private/` inside the webroot. The wizard records the app's
   real public path and base URL in the config, so uploads and the cron
   script work in either layout.

3. **Permissions**:
   - `public_html/uploads/` → `755` (must be writable by PHP; on cPanel with
     suPHP/LSAPI the PHP user is your account user, so 755 is enough)
   - `private/config/` → `750` (writable so the wizard can create `app.php`)
   - keep the shipped `uploads/.htaccess` — it blocks script execution there

4. **Run the setup wizard**: browse to `https://yourdomain/admin/login.php`.
   Because no config file exists yet, the wizard appears. Enter:
   - DB host (`localhost` on almost all cPanel hosts), DB name, DB user, DB password
   - SMTP host, port (465 = implicit SSL, 587 = STARTTLS), username, password,
     from address and from name — your cPanel email account works fine
     (host is usually `mail.yourdomain.com`, username is the full address)
   - the first admin email + password (min 10 chars)

   Submitting creates all tables from `schema.sql`, inserts the admin, and
   writes `private/config/app.php` (outside the webroot). The wizard is then
   permanently replaced by the normal login.

5. **Cron job** in cPanel → *Cron Jobs*, every 5 minutes:

   ```
   */5 * * * * /usr/local/bin/php -q /home/YOURUSER/private/cron/send_reveal_emails.php >/dev/null 2>&1
   ```

   (Some hosts use `/usr/bin/php`; check with `which php` in Terminal.)
   The script picks up events whose `email_send_at` has passed and whose
   assignments exist, emails every participant a "log in to see who you got"
   nudge, and marks the event `emailed`. Failed batches retry on the next run.

## Typical flow

1. Admin logs in (password → 6-digit code emailed → verify).
2. Admin creates an event with the email-send time and the reveal time.
3. Admin adds participants by email on the Users page (attached to the event).
4. Each participant requests a login code at `/login.php`, enters it at
   `/code.php`, and completes their profile (name, nickname, selfie ≤2MB).
5. Admin marks any couples on the Relationships page.
6. Admin hits Generate — a perfect 1-to-1 cycle is produced (no self, no
   partners). If constraints make it impossible, a clear error is shown and
   nothing is saved.
7. Cron sends the reveal emails at the scheduled time; after `reveal_at`,
   each user's dashboard shows *"You are buying a present for: [nickname]"*
   with the recipient's photo.
8. On the admin Assignments page recipients are masked; unmasking requires
   re-entering the admin password and lasts 60 minutes per session.

## Security notes

- All queries use PDO prepared statements (`ATTR_EMULATE_PREPARES = false`).
- CSRF token required on every POST; invalid tokens are rejected before any
  state change.
- Session cookies: HttpOnly, SameSite=Lax, Secure on HTTPS,
  `use_strict_mode`, ID regenerated on every privilege change.
- Login/MFA codes are stored bcrypt-hashed with 10-minute expiry, burned
  after use or 5 wrong attempts; issuing and verifying are throttled per
  email/IP via the `code_throttle` table.
- The user login page answers identically for known and unknown addresses.
- Uploads: extension + real MIME (finfo) + decodable-image checks, 2MB cap,
  randomized filenames, script execution disabled in `/uploads`.
- Recipient identities are never selected from the DB before `reveal_at`
  (user side) or without the unmask session flag (admin side) — masking is
  enforced in SQL, not just hidden in the UI.
