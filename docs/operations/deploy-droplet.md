# Deploy On A Single Droplet

> Generic single-server deployment: Nginx, PHP-FPM, MySQL and local storage for uploads. Provider-agnostic.
> Fits a single node with filesystem uploads; externalize media storage before scaling horizontally.

## When This Topology Fits

This setup is appropriate when:

- uploads are stored on the server filesystem
- the project runs on a single node
- the team wants a simple, inspectable infrastructure

It is less appropriate for horizontally scaled setups unless media storage is externalized first.

## Example Stack

- Ubuntu LTS
- Nginx
- PHP 8.3
- MySQL 8
- Node.js LTS

## 1. Prepare The Server

Install the base stack and confirm the required PHP extensions for the project are available.

Typical packages include:

- `nginx`
- `mysql-server`
- `php8.3-fpm`
- `php8.3-cli`
- `php8.3-mysql`
- `php8.3-mbstring`
- `php8.3-xml`
- `php8.3-zip`
- `php8.3-bcmath`
- `php8.3-intl`
- `php8.3-gd`
- `php8.3-curl`
- `php8.3-fileinfo`
- `git`
- `composer`
- `nodejs`

Confirm GD has WebP support enabled. The media optimizer requires `imagewebp`.

On small servers, adding swap may help avoid out-of-memory failures during dependency installation or builds.

## 2. Upload The Application

Choose a stable application path such as:

```text
/var/www/project
```

Copy code with a method such as:

- `git clone`
- `git pull`
- `rsync`
- CI artifact upload

Exclude environment files and transient directories when appropriate.

## 3. Configure The Database And Environment

Create a dedicated database and application user.

Set production environment values such as:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=project_database
DB_USERNAME=project_user
DB_PASSWORD=...
SESSION_SECURE_COOKIE=true
```

## 4. Install Dependencies And Build Assets

Typical commands:

```bash
cd /var/www/project
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Code should move through Git or deployment artifacts. Dynamic data should move through application snapshots:

```bash
# On the destination
git pull
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan migrate --force

# On the source
php artisan snapshot:create --name=production-transfer

# Copy storage/app/private/snapshots/production-transfer.appbackup to the destination, then:
php artisan snapshot:inspect /path/to/production-transfer.appbackup
php artisan snapshot:restore /path/to/production-transfer.appbackup
```

`snapshot:restore` prints the inspection report and asks you to accept each warning by key. Use `--yes` only in scripts. See [snapshots.md](../operations/snapshots.md) for the archive format, the risk keys, and housekeeping.

## 5. Set Permissions

Ensure the web server user can write to:

- `storage/`
- `bootstrap/cache/`

Use ownership and permissions appropriate to the host OS and deployment policy.

## 6. Configure Nginx

Point the server block root to:

```text
/var/www/project/public
```

Typical application rule:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

PHP requests should be forwarded to the correct PHP-FPM socket or port for the installed runtime.

## 7. Enable HTTPS

Use the team's preferred certificate workflow, such as:

- Let's Encrypt with Certbot
- a managed proxy or load balancer

Ensure HTTP redirects to HTTPS after validation.

## 8. Validate The Deployment

Minimum checks:

- the public site returns `200`
- admin login works
- uploads render from the public storage path
- a test upload succeeds
- application logs show no critical errors

## Operational Notes

- Remove any development-only hot-reload markers before production use.
- Re-deploys should follow the same sequence: code sync, dependencies, build, optimize, service reload.
- If the project stores uploads on local disk, document that this deployment is single-node by design.
- Prefer `php artisan snapshot:create` and `php artisan snapshot:restore` for production or large backups. Browser uploads can fail before Laravel runs when Nginx returns `413 Request Entity Too Large`, or when PHP drops the upload because of `upload_max_filesize` or `post_max_size`.
- If HTTP restore is unavoidable, raise compatible limits such as Nginx `client_max_body_size`, PHP `upload_max_filesize`, PHP `post_max_size`, and relevant PHP-FPM/Nginx timeouts.
- If a restore process dies while maintenance mode is active, run `php artisan up` manually after inspecting the failure.
- Snapshot tests use SQLite in memory, so the test environment requires `pdo_sqlite` even when production uses MySQL.
