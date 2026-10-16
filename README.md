# KAMALIG — Rice Supply Transparency Platform

PHP 8 + MySQL/MariaDB app for shared hosting (built for Hostinger). Roles: farmer,
importer, supplier, admin. Payments are **manual by design** (buyer uploads proof,
seller confirms); the `payments` table is ready for a gateway later.

> `.md` files are blocked by `.htaccess`, so this README is not served to visitors.

## 1. Install (fresh server)

1. Upload the contents of this folder to `public_html/`.
2. Create `.env` **one level above** `public_html/` (never inside it). Settings read by the app:

   | Setting | Purpose |
   |---|---|
   | `APP_ENV=production`, `APP_URL=https://yoursite` | production mode (blocks orders if payments are not set up), links in emails |
   | `APP_DEBUG=false`, `ENABLE_HTTPS=true` | no error display; HTTPS enforced in PHP as well as `.htaccess` |
   | `APP_KEY` | long random string; keys OTP hashing (do not change after launch: pending OTPs stop working) |
   | `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` | database |
   | `GMAIL_ENABLED=true` `GMAIL_EMAIL` `GMAIL_PASSWORD` `SMTP_HOST` `SMTP_PORT` `SMTP_SECURE` `MAIL_FROM_NAME` | sending OTP / reset / decision emails (Gmail **App Password**) |
   | `TURNSTILE_SITE_KEY` `TURNSTILE_SECRET_KEY` | CAPTCHA on sign-in, register, forgot password (off if either is empty) |
   | `GOOGLE_CLIENT_ID` `GOOGLE_CLIENT_SECRET` `GOOGLE_REDIRECT_URI` | Sign in with Google |
   | `GOOGLE_VISION_API_KEY` | OCR of JPG/PNG documents |
   | `ANTHROPIC_API_KEY` `ANTHROPIC_MODEL` | AI assistant and document checks. Set a **monthly spend limit** in the Anthropic console |
   | `PRIVATE_STORAGE_DIR` | optional absolute path for private files (default: `kamalig_private/` next to `public_html/`) |
   | `PAYMENT_DEADLINE_HOURS` (48) · `PAYMENT_REQUIRED_BEFORE_DISPATCH` (true) · `PAYMENT_METHODS` | payments |
   | `SESSION_IDLE_MINUTES` (30) · `SESSION_ABSOLUTE_HOURS` (8) · `TRUST_IP_HEADER` | sessions; set the header name only if behind a CDN/proxy |
   | `COMPLIANCE_PURGE_DAYS` (30) · `COMPLIANCE_ALLOW_BROWSE` (false) · `BACKUP_KEEP` (14) | compliance gate and backups |

3. Create the database structure from your schema baseline (`mysqldump --no-data` of the
   reference database, which includes the foreign keys), then bring it up to date:

   ```
   php scripts/migrate_schema.php            # dry run: lists anything missing
   php scripts/migrate_schema.php --apply    # adds login_attempts, payments.dueAt, consent columns
   ```
4. Create the admin (prompts for a password, never stored in a file):
   `php scripts/create_admin.php you@yourdomain.com`
5. Move any old public uploads into private storage (once): `php scripts/migrate_private_documents.php --apply`
6. Open **Admin → Security check**. Everything should be green before you go live.
7. Delete any installer/update files left on the server.

## 2. Scheduled jobs (Hostinger hPanel → Advanced → Cron Jobs)

```
0 * * * *   /usr/bin/php /home/USER/domains/SITE/public_html/scripts/payment_deadline_cron.php --apply
0 2 * * *   /usr/bin/php /home/USER/domains/SITE/public_html/scripts/compliance_cron.php --apply
30 2 * * *  /usr/bin/php /home/USER/domains/SITE/public_html/scripts/backup_cron.php
```

Every script runs as a dry run without `--apply` (backup_cron always writes). Each records a
heartbeat; **Admin → Security check** shows when each last ran.

## 3. Backups and restore (rehearse this once, and write down the date)

`backup_cron.php` writes two files to `<private storage>/backups/`:
`kamalig_backup_*.json` (all database records) and `kamalig_files_*.zip` (uploaded documents,
shipment documents, payment proofs). **A backup needs both halves.** They sit on the same
server as the app, so copy them off-server regularly (download from hPanel, or to cloud storage).

Restore on a clean or staging copy:

1. Install as in section 1 (schema + admin). 
2. Unzip the files archive into the private storage folder (it contains `documents/`, `shipments/`, `payment-proofs/`).
3. Sign in as admin → **Admin → Backup → Import**, choose the `.json`, mode **Merge** (adds what is
   missing, leaves existing rows alone) or **Overwrite** (replaces matching rows).
4. The import is all-or-nothing: if any row cannot be restored it rolls back and names the table and row.
5. Check: user count, one order with its payment, open one document and one receipt.

One-time codes (OTP) are deliberately not included in backups.

## 4. Before the defense

- Freeze the code, tag the release in git, deploy from the tag.
- `php scripts/agent_eval.php` and keep the output as evidence for the AI evaluation slide.
- Rehearse: register → approval → list → accept → pay → dispatch, and the two-simultaneous-Accepts test.
- Keep a recorded walkthrough and a local copy as a fallback.

## 5. Known limitations (say them yourself)

- Payments are manual; no gateway.
- Insights are trend indicators (last 30 days vs the 30 before), not predictions; PDFs are not OCR'd.
- `includes/data.php` is large and keeps a session fallback mode (disabled in production).
- Content-Security-Policy still allows inline scripts.
"# kamalig.site" 
