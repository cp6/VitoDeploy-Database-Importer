# VitoDeploy Database Importer

A VitoDeploy 4.x plugin for securely uploading `.sql`, `.sql.gz`, and `.zip` database dumps, reviewing the destination, and running the restore in Vito's background queue.

The interface follows the same responsive Vito-native design language as [VitoDeploy Forge Importer](https://github.com/cp6/VitoDeploy-Forge-Importer): a four-step workflow, Vito typography and tokens, dark mode, compatibility checks, progress, redacted logs, and retry controls.

## Workflow

1. Upload an `.sql`, `.sql.gz`, or `.zip` file.
2. Select a Vito server and confirm the source database engine.
3. Select or create the destination database.
4. Select or create a database user. The selected user is linked after restore.
5. Choose an empty-only or overwrite policy and optionally create a safety backup.
6. Review engine, server, destination-content, and disk-space checks.
7. Type the destination database name when a non-empty overwrite needs confirmation.
8. Queue the import and follow its progress, sanitized log, and final result.

Vito's administrative MySQL/MariaDB or PostgreSQL access performs the restore. The application database user's password is never added to an import command or log.

## Features

- Streams uploads to local storage instead of loading the dump into PHP memory.
- Supports raw SQL, gzip, and ZIP archives containing exactly one `.sql` file.
- Detects MySQL, MariaDB, and PostgreSQL dump signatures and checks destination compatibility.
- Selects or creates a database and database user using Vito's native actions.
- Links an existing user to multiple databases without removing its current links.
- Checks the destination database for existing objects.
- Checks remote disk space with configurable safety headroom.
- Creates an optional compressed safety backup before clearing a non-empty database.
- Uses Vito's native database backup/restore command templates.
- Runs imports on Vito's `ssh` queue and reports durable progress.
- Redacts password, token, secret, credential URL, and command-password patterns from errors.
- Retains a failed upload for a configurable retry window, then deletes it automatically.
- Deletes successful staged uploads immediately and cleans remote temporary imports.
- Supports explicit cancellation between import stages.
- Ships a compiled Tailwind stylesheet; Node.js is not required in production.

## Archive protections

ZIP uploads are opened without extracting their paths. The plugin rejects:

- absolute paths and `..` traversal segments;
- symbolic links;
- encrypted entries;
- archives with too many entries;
- archives with more than one SQL file;
- unsafe compression ratios;
- files over the configured extracted-size limit.

Gzip data is streamed once during inspection to verify it and enforce the extracted-size limit.

## Requirements

- VitoDeploy 4.x
- PHP 8.4 or newer
- PHP zlib extension
- PHP zip extension when accepting `.zip` uploads
- A running Vito `default` and `ssh` queue worker
- A ready destination server with MySQL, MariaDB, or PostgreSQL installed
- PHP `upload_max_filesize` and `post_max_size` values at least as large as `max_upload_mb`
- Enough free space on the Vito host for staged uploads and on the destination host for restore work

## Installation

1. In Vito, open **Admin → Plugins**.
2. Choose the GitHub/quick-install option.
3. Enter this repository URL.
4. Install and enable **Database Importer**.
5. Ensure Vito's queue workers are running.
6. Open a server and select **Features → Database Importer → Open Importer**.

For local development, clone the repository into the Vito application at:

```text
app/Vito/Plugins/Cp6/VitoDeployDatabaseImporter
```

Then install and enable it from Vito's plugin administration screen.

## Configuration

Defaults are defined in `config/database-import.php`:

| Option | Default | Purpose |
| --- | ---: | --- |
| `disk` | `local` | Laravel filesystem disk for staged uploads. |
| `max_upload_mb` | `2048` | Maximum compressed upload size. |
| `max_extracted_mb` | `8192` | Maximum raw SQL size after decompression. |
| `max_zip_entries` | `20` | Maximum ZIP central-directory entries. |
| `max_zip_ratio` | `200` | Maximum uncompressed-to-compressed ratio for the SQL entry. |
| `minimum_remote_headroom_mb` | `512` | Free space reserved beyond the estimated import requirement. |
| `failed_file_retention_hours` | `24` | Retry window before a failed staged upload is deleted. |
| `drop_tables_on_uninstall` | `false` | Delete plugin history when uninstalling. |

Override these values through the host application's `database-import` configuration.

## Safety backup behavior

Before an approved overwrite, the plugin can create:

```text
/home/{vito-ssh-user}/.vito-database-importer/backups/{database}-before-import-{timestamp}.sql.gz
```

The final result displays the exact path. These safety backups are intentionally retained until an operator verifies the imported database and removes them.

## Retry and cleanup

- Successful imports delete the local staged upload immediately.
- Failed imports retain the original staged upload for `failed_file_retention_hours` and expose **Retry failed import**.
- A delayed cleanup job deletes expired failed or cancelled uploads.
- Opening the importer also queues cleanup for stale rows, covering delayed-job interruptions.
- Normalized local files and remote restore archives are removed in `finally` cleanup paths.

## Frontend development

```bash
npm install
npm run build:css
```

Commit `resources/dist/importer.css` with releases.

## Tests

Place the plugin in a VitoDeploy 4.x checkout, then run:

```bash
php artisan test app/Vito/Plugins/Cp6/VitoDeployDatabaseImporter/tests
```

## License

[MIT](LICENSE)
