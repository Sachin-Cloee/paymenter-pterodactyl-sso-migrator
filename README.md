# Paymenter PterodactylSSO migration wizard

An interactive CLI wizard for moving an existing
[PterodactylSSO](https://github.com/Sachin-Cloee/pterodactyl-sso) installation
in Paymenter from a Pterodactyl **1.x** panel to **2.x**.

It asks the questions, creates a full database backup, updates the Paymenter
server row/keys/secret, optionally moves products between an old and a new
panel row, and then runs the extension's hardened migration command.

## Requirements

- Paymenter with the **PterodactylSSO** server extension **2.6.0 or newer**
  (the `pterodactyl-sso:migrate-v2` command must exist).
- Pterodactyl **2.x** panel with the panel-side `paymenter-sso` extension
  installed, enabled and configured with a shared secret.
- PHP 8.3+, MySQL/MariaDB, and `mysqldump` or `mariadb-dump` on the
  Paymenter server.

## Run

From the Paymenter root (the folder containing `artisan`):

```bash
curl -fsSL https://github.com/Sachin-Cloee/paymenter-pterodactyl-sso-migrator/raw/main/migrate.php -o /tmp/paymenter-sso-migrate.php && php /tmp/paymenter-sso-migrate.php
```

The download is intentionally temporary: running this command again fetches
the latest wizard. The underlying migration command is updated with the
PterodactylSSO Paymenter extension.

### What it asks

1. Whether to run the full migration.
2. Whether you have **two separate panels/servers** (old 1.x and new 2.x).
3. Which Paymenter server row is the target (or the old and new rows).
4. The server row name.
5. Whether the panel URL/API keys are already correct, or new keys for:
   - Application API key
   - Admin API key (2.x)
   - Client API key
6. The SSO shared secret from the panel extension.
7. Whether to move products from the old row to the new row (two-panel mode).
8. Final confirmation.

The script shows a summary and asks again before writing.

## Backups and safety

Before it changes anything, the wizard writes and verifies a full database
dump under:

```text
storage/app/backups/pterodactyl-sso-wizard-<timestamp>-<random>/database.sql
```

It then updates the target row in one transaction and runs the migration
command's own read-only preflight **inside that transaction**. If the panel,
keys or SSO secret are wrong, the row changes are rolled back automatically.

The migration command takes a second backup before its own writes. Both
directories contain full database dumps; treat them as sensitive.

## Two separate panels

If you built a new Pterodactyl 2.x panel and created a second Paymenter server
row:

1. Select the old row as **source** and the new row as **target**.
2. Point the matching customer servers at the **new** panel. They must exist
   on the new panel with the same external ID as the Paymenter service ID.
3. Let the wizard move the products from the old row to the new row and run
   the migration.

Services whose servers are missing on the new panel are skipped with a warning
and will need manual attention. The old server row is left in place; remove or
disable it only after verifying the new panel.

## Rollback

The pre-migration dump is the authoritative rollback for changes made by the
wizard (server row name, keys, secret and product reassignment):

```bash
mysql -u root -p paymenter < storage/app/backups/pterodactyl-sso-wizard-.../database.sql
```

The hardened migration command prints its own `undo.json`; use its rollback for
the migration changes:

```bash
php artisan pterodactyl-sso:migrate-v2 --rollback=/path/to/undo.json --apply
```

## Run as the Paymenter user

Run the wizard as the user that owns the Paymenter files (for example
`www-data`), not root. Root-owned backups may not be readable by the web user.

## License

MIT
