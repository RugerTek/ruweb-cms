# Deploy On cPanel

> Generic cPanel deployment. Replace placeholder values with the target account's. Database rows and uploads
> travel as a snapshot ([snapshots.md](snapshots.md)), never through git.

## Assumptions

- PHP 8.3 or newer is available
- MySQL is available
- cPanel Terminal access is enabled
- the domain or subdomain points its document root to the project's `public/` directory
- required PHP extensions are enabled, including `pdo_mysql`, `mbstring`, `xml`, `fileinfo`, `gd` with WebP support, `zip`, `ctype`, and `openssl`

## Folder Layout

Typical layout:

```text
/home/ACCOUNT_NAME/
├── public_html/
└── PROJECT_DIRECTORY/
    ├── app/
    ├── public/
    └── ...
```

The domain or subdomain document root should point to:

```text
/home/ACCOUNT_NAME/PROJECT_DIRECTORY/public
```

## Deployment Steps

### 1. Build frontend assets

```bash
npm run build
```

### 2. Upload project files

Upload the project without transient dependencies such as `node_modules/`.

### 3. Use the hosting PHP binary

Some cPanel environments expose a system PHP version that differs from the selected site version. Confirm the correct binary before running composer or artisan commands.

Example:

```bash
alias php=/path/to/cpanel/php
php -v
```

### 4. Install dependencies

Prefer:

```bash
composer install --no-dev --optimize-autoloader
```

Use `composer update` only when the lock file is intentionally being regenerated for the target runtime.

### 5. Configure `.env`

Minimum production values:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=project_database
DB_USERNAME=project_user
DB_PASSWORD=...
```

### 6. Run application setup commands

```bash
php artisan key:generate
php artisan migrate --force
php artisan db:seed
php artisan storage:link
php artisan optimize
```

Run only the commands that are appropriate for the environment. For example, skip `db:seed` if production seeding is not desired.

### 7. Move database and uploads with snapshots

Move code with Git, Composer, and the build output. Move dynamic data with `.appbackup` snapshots:

```bash
# On the origin environment
php artisan snapshot:create --name=origin

# Upload origin.appbackup to the destination account, then:
php artisan snapshot:inspect /home/ACCOUNT_NAME/origin.appbackup
php artisan snapshot:restore /home/ACCOUNT_NAME/origin.appbackup
```

`snapshot:restore` prints the inspection report and asks you to accept each warning by key. Use `--yes` only in scripts. See [snapshots.md](../operations/snapshots.md) for the archive format, the risk keys, and housekeeping.

### 8. Fix writable permissions

Ensure the web server can write to:

- `storage/`
- `bootstrap/cache/`

## Operational Notes

- The PHP alias may last only for the current shell session.
- Keep deployment commands consistent between environments.
- Re-deploys should generally use `composer install`, not `composer update`.
- Avoid embedding account names, real domains, or credentials in this guide.
- Prefer CLI snapshot restore for production and large backups. cPanel, Nginx, Apache, PHP-FPM, or PHP itself can reject large browser uploads before Laravel sees the request.
- For HTTP uploads, align limits such as `upload_max_filesize`, `post_max_size`, web server body size, and request timeouts. A `413 Request Entity Too Large` response is usually emitted by the web server before Laravel can show an application error.
- If maintenance mode remains active after an interrupted restore, run `php artisan up`.
- Snapshot tests use SQLite in memory, so local test runs require `pdo_sqlite`.
