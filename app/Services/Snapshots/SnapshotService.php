<?php

namespace App\Services\Snapshots;

use App\Services\Revisions\ContentRevisionLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class SnapshotService
{
    public const FORMAT_ID = 'ruweb-cms.appbackup';

    public const FORMAT_VERSION = 2;

    /** Older formats that can still be restored, with a `legacy_format` warning. */
    public const SUPPORTED_FORMAT_VERSIONS = [1, 2];

    /**
     * Tables that travel inside the archive for inspection but are never
     * written into the destination. The destination keeps its own migration
     * history so `php artisan migrate` stays truthful after a restore.
     */
    protected const RESTORE_SKIPPED_TABLES = [
        'migrations',
    ];

    protected const EXCLUDED_TABLES = [
        'custom_code_receipts',
        'mcp_api_keys',
        'admin_sessions',
        'cache',
        'cache_locks',
        'content_edit_locks',
        'editorial_drafts',
        'editorial_preview_links',
        'failed_jobs',
        'job_batches',
        'jobs',
        'oauth_access_tokens',
        'oauth_authorization_codes',
        'password_reset_tokens',
        'sessions',
    ];

    protected const TABLE_PRIORITY = [
        'migrations',
        'users',
        'custom_code_snippets',
        'custom_code_versions',
        'custom_code_events',
        'settings',
        'pages',
        'posts',
        'media',
        'media_translations',
        'taxonomies',
        'menus',
        'menu_items',
        'mediables',
        'taxables',
    ];

    public function __construct(
        protected EnvironmentReportService $environment,
        protected ContentRevisionLedger $revisions
    ) {}

    public function create(?string $name = null, ?string $directory = null): string
    {
        $this->assertZipAvailable();
        $this->assertStorageWritable();

        $directory ??= self::snapshotsDirectory();
        File::ensureDirectoryExists($directory);

        $safeName = $this->safeArchiveName($name ?: 'snapshot-'.now()->format('Ymd-His'));
        $archivePath = $directory.DIRECTORY_SEPARATOR.$safeName.'.appbackup';
        $workDir = self::workDirectory().'/'.uniqid('snapshot-', true);

        File::ensureDirectoryExists($workDir.'/database');
        File::ensureDirectoryExists($workDir.'/uploads');

        try {
            $tableEntries = $this->exportDatabase($workDir);
            $uploadStats = $this->exportUploads($workDir);

            $manifest = [
                'format' => self::FORMAT_ID,
                'version' => self::FORMAT_VERSION,
                'created_at' => Carbon::now()->toIso8601String(),
                'app' => [
                    'version' => Config::get('cms.version'),
                    'laravel_version' => App::version(),
                    'env' => App::environment(),
                    'url' => Config::get('app.url'),
                    'commit' => $this->environment->report()['app']['commit'] ?? null,
                ],
                'database' => [
                    'connection' => DB::getDefaultConnection(),
                    'driver' => DB::connection()->getDriverName(),
                    'tables' => $tableEntries,
                    'excluded_tables' => array_values(array_intersect(self::EXCLUDED_TABLES, $this->currentTables())),
                    'migrations' => $this->ranMigrations(),
                    'schema' => $this->schemaForTables(array_column($tableEntries, 'name')),
                ],
                'uploads' => [
                    'logical_root' => 'public',
                    'files' => $uploadStats['files'],
                    'bytes' => $uploadStats['bytes'],
                ],
                'history' => [
                    'head' => $this->revisions->head()?->hash,
                    'count' => $this->revisions->count(),
                ],
            ];

            $this->writeJson($workDir.'/manifest.json', $manifest);
            $this->writeJson($workDir.'/checksums.json', $this->checksumsForDirectory($workDir));
            $this->zipDirectory($workDir, $archivePath);

            return $archivePath;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Inspect an archive against the current installation without changing
     * anything. Structural problems (bad ZIP, checksum mismatch, unsafe paths)
     * still throw; everything else is reported as a risk.
     */
    public function inspect(string $archivePath): SnapshotReport
    {
        $this->assertZipAvailable();
        $this->assertArchiveExists($archivePath);

        $extractDir = self::restoreDirectory().'/'.uniqid('inspect-', true);

        try {
            File::ensureDirectoryExists($extractDir);
            $this->extractArchiveSafely($archivePath, $extractDir);

            return $this->buildReport($extractDir);
        } finally {
            File::deleteDirectory($extractDir);
        }
    }

    /**
     * Restore an archive. Every warning in the inspection report must be
     * listed in `$accepted` by key, and blocking risks can never be bypassed.
     *
     * @param  array<int, string>  $accepted  Risk keys the operator accepted.
     * @param  array{url_from?: ?string, url_to?: ?string}  $options  Override the origin rewrite
     *                                                                (defaults: manifest `app.url` → this installation's `app.url`).
     */
    public function restore(string $archivePath, array $accepted = [], array $options = []): array
    {
        $this->assertZipAvailable();
        $this->assertStorageWritable();
        $this->assertArchiveExists($archivePath);

        $extractDir = self::restoreDirectory().'/'.uniqid('restore-', true);
        $preRestore = null;

        try {
            File::ensureDirectoryExists($extractDir);
            $this->extractArchiveSafely($archivePath, $extractDir);

            $report = $this->buildReport($extractDir);
            $report->assertRestorable($accepted);

            $manifest = $report->manifest;
            $tables = $report->tables;
            $rewriter = UrlRewriter::between(
                $options['url_from'] ?? ($manifest['app']['url'] ?? null),
                $options['url_to'] ?? Config::get('app.url')
            );

            $preRestore = $this->create('pre-restore-'.now()->format('Ymd-His'));

            $maintenance = (bool) Config::get('cms.snapshots.maintenance_mode', true);

            if ($maintenance) {
                Artisan::call('down', ['--retry' => 60]);
            }

            try {
                $this->restoreDatabase($extractDir, $tables, $rewriter);
                $this->replaceUploads($extractDir.'/uploads');
                Artisan::call('storage:link');
                Artisan::call('optimize:clear');
            } finally {
                if ($maintenance) {
                    Artisan::call('up');
                }
            }

            return [
                'pre_restore_backup' => $preRestore,
                'tables' => count($tables),
                'uploads_files' => (int) ($manifest['uploads']['files'] ?? 0),
                'url_rewritten' => $rewriter ? ['from' => $rewriter->from(), 'to' => $rewriter->to()] : null,
                'report' => $report,
            ];
        } finally {
            File::deleteDirectory($extractDir);
        }
    }

    /**
     * Build the inspection report from an already extracted archive.
     */
    protected function buildReport(string $extractDir): SnapshotReport
    {
        $manifest = $this->readManifest($extractDir);
        $this->validateChecksums($extractDir);

        $allTables = $this->manifestTables($manifest);
        $this->assertRestoreTablesAreSafe($allTables);

        $tables = array_values(array_filter(
            $allTables,
            fn (array $table): bool => ! in_array($table['name'], array_merge(self::RESTORE_SKIPPED_TABLES, self::EXCLUDED_TABLES), true)
        ));

        $destination = [
            'version' => (string) Config::get('cms.version'),
            'driver' => DB::connection()->getDriverName(),
            'url' => Config::get('app.url'),
            'laravel_version' => App::version(),
            'revisions' => $this->revisions->count(),
        ];

        $risks = [];

        if ((int) $manifest['version'] < self::FORMAT_VERSION) {
            $risks[] = SnapshotRisk::warning(
                'legacy_format',
                'The snapshot uses an older archive format, so some compatibility checks cannot run.',
                ['snapshot' => (int) $manifest['version'], 'current' => self::FORMAT_VERSION]
            );
        }

        $missing = $this->missingDestinationTables($tables);

        if ($missing !== []) {
            $risks[] = SnapshotRisk::blocking(
                'missing_tables',
                'Destination is missing required tables: '.implode(', ', $missing).'. Update the destination code and run migrations first.',
                ['tables' => $missing]
            );
        }

        array_push($risks, ...$this->versionRisks($manifest, $destination['version']));
        array_push($risks, ...$this->historyRisks($extractDir, $tables));

        if ($missing === []) {
            array_push($risks, ...$this->schemaRisks($manifest, $tables));
        }

        $driver = $manifest['database']['driver'] ?? null;

        if ($driver !== $destination['driver']) {
            $risks[] = SnapshotRisk::warning(
                'driver_mismatch',
                "Database driver differs from snapshot ({$driver} → {$destination['driver']}).",
                ['snapshot' => $driver, 'destination' => $destination['driver']]
            );
        }

        $originUrl = $manifest['app']['url'] ?? null;

        if ($originUrl && rtrim((string) $originUrl, '/') !== rtrim((string) $destination['url'], '/')) {
            $risks[] = SnapshotRisk::info(
                'origin_url_differs',
                "Snapshot was taken from {$originUrl}; this installation runs at {$destination['url']}. Stored URLs will be rewritten to the new origin during restore.",
                ['snapshot' => $originUrl, 'destination' => $destination['url']]
            );
        }

        return new SnapshotReport($manifest, $tables, $risks, $destination);
    }

    protected function exportDatabase(string $workDir): array
    {
        return collect($this->orderedTablesForExport())
            ->map(function (string $table) use ($workDir): array {
                $relativePath = "database/{$table}.jsonl";
                $path = $workDir.'/'.$relativePath;
                $handle = fopen($path, 'wb');

                if ($handle === false) {
                    throw new SnapshotException("Unable to write table export: {$relativePath}");
                }

                $rows = 0;

                try {
                    foreach ($this->orderedTableQuery($table)->cursor() as $row) {
                        fwrite($handle, json_encode($table === 'users' ? $this->stripAccountCredentials((array) $row) : (array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
                        $rows++;
                    }
                } finally {
                    fclose($handle);
                }

                return [
                    'name' => $table,
                    'file' => $relativePath,
                    'rows' => $rows,
                    'checksum' => hash_file('sha256', $path),
                ];
            })
            ->values()
            ->all();
    }

    protected function exportUploads(string $workDir): array
    {
        $source = Storage::disk('public')->path('');
        $target = $workDir.'/uploads';
        $files = 0;
        $bytes = 0;

        if (! is_dir($source)) {
            return ['files' => 0, 'bytes' => 0];
        }

        foreach (File::allFiles($source) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            if ($relative === '.gitignore') {
                continue;
            }

            $destination = $target.'/'.$relative;
            File::ensureDirectoryExists(dirname($destination));
            File::copy($file->getPathname(), $destination);
            $files++;
            $bytes += $file->getSize();
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    protected function restoreDatabase(string $extractDir, array $tables, ?UrlRewriter $rewriter = null): void
    {
        $this->setForeignKeyChecks(false);
        DB::beginTransaction();

        try {
            $tableNames = array_column($tables, 'name');
            if (array_intersect(['pages', 'posts'], $tableNames)) {
                // User drafts/share links are installation-local; old archives must not inherit
                // destination histories or reviews attached to reused content ids.
                foreach (['editorial_drafts', 'editorial_preview_links', 'editorial_review_comments', 'editorial_versions', 'editorial_reviews'] as $editorialTable) {
                    if (Schema::hasTable($editorialTable) && ! in_array($editorialTable, $tableNames, true)) {
                        DB::table($editorialTable)->delete();
                    }
                }
            }
            if (in_array('contact_submissions', $tableNames, true) && ! in_array('contact_submission_notes', $tableNames, true)
                && Schema::hasTable('contact_submission_notes')) {
                DB::table('contact_submission_notes')->delete();
            }
            if (in_array('users', $tableNames, true)) {
                foreach (['mcp_api_keys', 'admin_sessions', 'oauth_access_tokens', 'oauth_authorization_codes', 'password_reset_tokens', 'sessions'] as $credentials) {
                    if (Schema::hasTable($credentials)) {
                        DB::table($credentials)->delete();
                    }
                }
            }
            // Older snapshots contain media but predate localized metadata. Do not let
            // destination overrides attach themselves to restored, potentially reused ids.
            if (in_array('media', $tableNames, true) && ! in_array('media_translations', $tableNames, true)
                && Schema::hasTable('media_translations')) {
                DB::table('media_translations')->delete();
            }
            foreach (array_reverse($tables) as $table) {
                DB::table($table['name'])->delete();
            }

            foreach ($tables as $table) {
                $this->importTable($extractDir.'/'.$table['file'], $table['name'], $rewriter);
            }

            if (in_array('contact_submissions', $tableNames, true) && Schema::hasColumn('contact_submissions', 'notification_status')) {
                // Queue jobs do not travel with snapshots. A visible retryable failure is safer
                // than a permanent queued indicator or delivery by a stale destination job.
                DB::table('contact_submissions')->whereIn('notification_status', ['queued', 'sending', 'retrying'])->update([
                    'notification_status' => 'failed', 'notification_token' => null,
                    'notification_started_at' => null, 'notification_failed_at' => now(),
                    'notification_error' => 'Delivery queue was reset during snapshot restore.',
                ]);
                DB::table('contact_submissions')->whereNotNull('notified_at')->update(['notification_status' => 'sent']);
            }

            // A restore must never execute imported code or attach old receipts to reused IDs.
            // Legacy archives without these tables also leave destination snippets inactive.
            if (Schema::hasTable('custom_code_receipts')) DB::table('custom_code_receipts')->delete();
            if (Schema::hasTable('custom_code_snippets')) {
                DB::table('custom_code_snippets')->update(['state' => 'inactive', 'active_version_id' => null, 'generation' => DB::raw('generation + 1'), 'updated_at' => now()]);
                if (Schema::hasTable('custom_code_events')) {
                    foreach (DB::table('custom_code_snippets')->orderBy('id')->pluck('id') as $id) {
                        DB::table('custom_code_events')->insert(['snippet_id' => $id, 'user_id' => null, 'action' => 'restore_inactive', 'version_id' => null, 'detail' => json_encode(['reason' => 'Explicit destination review and activation required after any snapshot restore.']), 'created_at' => now()]);
                    }
                }
            }

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        } finally {
            $this->setForeignKeyChecks(true);
        }
    }

    protected function stripAccountCredentials(array $row): array
    {
        foreach (['remember_token', 'mcp_api_key_hash', 'mcp_api_key_generated_at', 'mcp_api_key_last_used_at',
            'pending_email', 'pending_email_token', 'pending_email_expires_at', 'two_factor_secret',
            'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_counter'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = null;
            }
        }
        if (array_key_exists('can_manage_custom_code', $row)) $row['can_manage_custom_code'] = false;
        if (array_key_exists('security_version', $row)) {
            $row['security_version'] = 0;
        }

        return $row;
    }

    protected function importTable(string $path, string $table, ?UrlRewriter $rewriter = null): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new SnapshotException("Unable to read table import: {$table}");
        }

        $batch = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if ($table === 'users') {
                    $row = $this->stripAccountCredentials($row);
                    if (Schema::hasColumn('users', 'security_version')) {
                        $row['security_version'] = random_int(1000000, 2000000000);
                    }
                }
                $batch[] = $rewriter ? $rewriter->rewriteRow($row) : $row;

                if (count($batch) >= 500) {
                    DB::table($table)->insert($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                DB::table($table)->insert($batch);
            }
        } finally {
            fclose($handle);
        }
    }

    protected function replaceUploads(string $source): void
    {
        $destination = rtrim(Storage::disk('public')->path(''), DIRECTORY_SEPARATOR);
        $backup = self::uploadsRollbackDirectory().'/'.uniqid('uploads-', true);

        File::ensureDirectoryExists(dirname($backup));

        if (is_dir($destination)) {
            File::moveDirectory($destination, $backup, true);
        }

        try {
            File::ensureDirectoryExists($destination);

            if (is_dir($source)) {
                File::copyDirectory($source, $destination);
            }

            // The export skips .gitignore on purpose; put the repo's own back so
            // the uploads folder stays ignored after a restore.
            if (is_file($backup.'/.gitignore') && ! is_file($destination.'/.gitignore')) {
                File::copy($backup.'/.gitignore', $destination.'/.gitignore');
            }
        } catch (\Throwable $exception) {
            File::deleteDirectory($destination);

            if (is_dir($backup)) {
                File::moveDirectory($backup, $destination, true);
            }

            throw $exception;
        }

        if (is_dir($backup)) {
            File::deleteDirectory($backup);
        }
    }

    protected function extractArchiveSafely(string $archivePath, string $extractDir): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw new SnapshotException('Invalid or unreadable ZIP archive.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $this->assertSafePath($name);
            }

            if (! $zip->extractTo($extractDir)) {
                throw new SnapshotException('Unable to extract snapshot archive.');
            }
        } finally {
            $zip->close();
        }
    }

    protected function readManifest(string $extractDir): array
    {
        $path = $extractDir.'/manifest.json';

        if (! is_file($path)) {
            throw new SnapshotException('Snapshot manifest.json is missing.');
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT_ID) {
            throw new SnapshotException('Snapshot manifest is invalid.');
        }

        if (! in_array((int) ($manifest['version'] ?? 0), self::SUPPORTED_FORMAT_VERSIONS, true)) {
            throw new SnapshotException('Unsupported snapshot format version.');
        }

        return $manifest;
    }

    protected function validateChecksums(string $extractDir): void
    {
        $path = $extractDir.'/checksums.json';

        if (! is_file($path)) {
            throw new SnapshotException('Snapshot checksums.json is missing.');
        }

        $checksums = json_decode((string) file_get_contents($path), true);

        if (! is_array($checksums)) {
            throw new SnapshotException('Snapshot checksums.json is invalid.');
        }

        $actualFiles = collect(File::allFiles($extractDir))
            ->map(fn ($file): string => str_replace('\\', '/', $file->getRelativePathname()))
            ->reject(fn (string $relativePath): bool => $relativePath === 'checksums.json')
            ->sort()
            ->values()
            ->all();

        $expectedFiles = collect(array_keys($checksums))->sort()->values()->all();

        if ($actualFiles !== $expectedFiles) {
            throw new SnapshotException('Snapshot file list does not match checksums.json.');
        }

        foreach ($checksums as $relativePath => $checksum) {
            $this->assertSafePath((string) $relativePath);

            $file = $extractDir.'/'.$relativePath;

            if (! is_file($file) || hash_file('sha256', $file) !== $checksum) {
                throw new SnapshotException("Checksum mismatch for {$relativePath}.");
            }
        }
    }

    protected function assertRestoreTablesAreSafe(array $tables): void
    {
        foreach ($tables as $table) {
            $name = (string) $table['name'];

            if ($name === '' || str_contains($name, '.') || str_contains($name, '\\') || str_contains($name, '/')) {
                throw new SnapshotException("Unsafe or schema-qualified table name rejected: {$name}");
            }

            $this->assertSafePath((string) $table['file']);
        }
    }

    /**
     * Compare the snapshot's application version and migration history with
     * this installation.
     *
     * @return array<int, SnapshotRisk>
     */
    protected function versionRisks(array $manifest, string $destinationVersion): array
    {
        $risks = [];
        $snapshotVersion = $manifest['app']['version'] ?? null;

        if (! is_string($snapshotVersion) || $snapshotVersion === '') {
            $risks[] = SnapshotRisk::warning(
                'unknown_origin_version',
                'The snapshot does not record the application version it was taken from, so version compatibility cannot be verified.',
                ['destination' => $destinationVersion]
            );
        } elseif (version_compare($snapshotVersion, $destinationVersion, '>')) {
            $risks[] = SnapshotRisk::blocking(
                'newer_snapshot',
                "The snapshot was taken on version {$snapshotVersion} but this installation runs {$destinationVersion}. Update the code to at least {$snapshotVersion} and run migrations first.",
                ['snapshot' => $snapshotVersion, 'destination' => $destinationVersion]
            );
        }

        $snapshotMigrations = $manifest['database']['migrations'] ?? null;

        if (! is_array($snapshotMigrations)) {
            return $risks;
        }

        $destinationMigrations = $this->ranMigrations();
        $unknown = array_values(array_diff($snapshotMigrations, $destinationMigrations));
        $behind = array_values(array_diff($destinationMigrations, $snapshotMigrations));

        if ($unknown !== []) {
            $risks[] = SnapshotRisk::blocking(
                'unknown_migrations',
                'The snapshot ran migrations this installation does not have: '.implode(', ', $unknown).'. Update the code first.',
                ['migrations' => $unknown]
            );
        }

        if ($behind !== []) {
            $risks[] = SnapshotRisk::warning(
                'older_snapshot',
                'This installation ran '.count($behind).' migration(s) after the snapshot was taken. Data migrations among them will not re-run on the restored rows: '.implode(', ', $behind),
                ['snapshot' => $snapshotVersion, 'destination' => $destinationVersion, 'migrations' => $behind]
            );
        }

        return $risks;
    }

    /**
     * Compare the snapshot's recorded columns with the destination tables.
     *
     * @return array<int, SnapshotRisk>
     */
    protected function schemaRisks(array $manifest, array $tables): array
    {
        $schema = $manifest['database']['schema'] ?? null;

        if (! is_array($schema)) {
            return [];
        }

        $missingColumns = [];
        $requiredColumns = [];

        foreach ($tables as $table) {
            $name = (string) $table['name'];
            $snapshotColumns = $schema[$name] ?? null;

            if (! is_array($snapshotColumns)) {
                continue;
            }

            $destinationColumns = Schema::getColumns($name);
            $destinationNames = array_column($destinationColumns, 'name');

            foreach (array_diff($snapshotColumns, $destinationNames) as $column) {
                $missingColumns[] = "{$name}.{$column}";
            }

            foreach ($destinationColumns as $column) {
                if (in_array($column['name'], $snapshotColumns, true)) {
                    continue;
                }

                if (! $column['nullable'] && $column['default'] === null && ! ($column['auto_increment'] ?? false)) {
                    $requiredColumns[] = "{$name}.{$column['name']}";
                }
            }
        }

        $risks = [];

        if ($missingColumns !== []) {
            $risks[] = SnapshotRisk::blocking(
                'missing_columns',
                'This installation lacks columns the snapshot rows contain: '.implode(', ', $missingColumns).'. Update the code and run migrations first.',
                ['columns' => $missingColumns]
            );
        }

        if ($requiredColumns !== []) {
            $risks[] = SnapshotRisk::blocking(
                'required_columns_missing',
                'This installation requires columns the snapshot does not provide and that have no default: '.implode(', ', $requiredColumns).'.',
                ['columns' => $requiredColumns]
            );
        }

        return $risks;
    }

    /** @return array<int, string> */
    protected function ranMigrations(): array
    {
        if (! Schema::hasTable('migrations')) {
            return [];
        }

        return DB::table('migrations')->orderBy('migration')->pluck('migration')->map(fn ($m): string => (string) $m)->values()->all();
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<string, array<int, string>>
     */
    protected function schemaForTables(array $tables): array
    {
        $schema = [];

        foreach ($tables as $table) {
            $schema[$table] = Schema::getColumnListing($table);
        }

        return $schema;
    }

    /**
     * Does this installation hold content changes the snapshot lacks?
     *
     * @return array<int, SnapshotRisk>
     */
    protected function historyRisks(string $extractDir, array $tables): array
    {
        $destinationCount = $this->revisions->count();

        if ($destinationCount === 0) {
            return [];
        }

        $entry = collect($tables)->firstWhere('name', ContentRevisionLedger::TABLE);

        if ($entry === null || ! is_file($extractDir.'/'.$entry['file'])) {
            return [SnapshotRisk::warning(
                'unknown_history',
                "The snapshot carries no revision ledger, so it cannot be verified that it contains the {$destinationCount} change(s) recorded here.",
                ['destination_revisions' => $destinationCount]
            )];
        }

        $hashes = [];
        $handle = fopen($extractDir.'/'.$entry['file'], 'rb');

        if ($handle === false) {
            throw new SnapshotException('Unable to read the snapshot revision ledger.');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $row = json_decode(trim($line), true);

                if (is_array($row) && isset($row['hash'])) {
                    $hashes[(string) $row['hash']] = true;
                }
            }
        } finally {
            fclose($handle);
        }

        $comparison = $this->revisions->compareWith($hashes);

        if ($comparison['diverged'] > 0) {
            return [SnapshotRisk::warning(
                'diverged_history',
                "This installation has {$comparison['diverged']} change(s) the snapshot does not contain. Restoring will discard them (the automatic pre-restore backup keeps a copy).",
                $comparison
            )];
        }

        return [SnapshotRisk::info(
            'history_contained',
            "All {$comparison['total']} change(s) recorded here are included in the snapshot. Restoring is a clean fast-forward.",
            $comparison
        )];
    }

    /** @return array<int, string> */
    protected function missingDestinationTables(array $tables): array
    {
        $existing = $this->currentTables();

        return collect($tables)
            ->pluck('name')
            ->reject(fn (string $table): bool => in_array($table, $existing, true))
            ->values()
            ->all();
    }

    protected function manifestTables(array $manifest): array
    {
        $tables = $manifest['database']['tables'] ?? null;

        if (! is_array($tables)) {
            throw new SnapshotException('Snapshot manifest has no table list.');
        }

        return collect($tables)
            ->map(function ($table): array {
                if (! is_array($table) || ! isset($table['name'], $table['file'])) {
                    throw new SnapshotException('Snapshot manifest contains an invalid table entry.');
                }

                return $table;
            })
            ->values()
            ->all();
    }

    protected function orderedTablesForExport(): array
    {
        return collect($this->currentTables())
            ->reject(fn (string $table): bool => in_array($table, self::EXCLUDED_TABLES, true))
            ->sortBy(fn (string $table): array => [
                ($priority = array_search($table, self::TABLE_PRIORITY, true)) === false ? 999 : $priority,
                $table,
            ])
            ->values()
            ->all();
    }

    protected function currentTables(): array
    {
        return $this->environment->currentSchemaTables();
    }

    protected function orderedTableQuery(string $table)
    {
        $query = DB::table($table);

        if (Schema::hasColumn($table, 'id')) {
            return $query->orderBy('id');
        }

        if (Schema::hasColumn($table, 'created_at')) {
            return $query->orderBy('created_at');
        }

        return $query;
    }

    protected function setForeignKeyChecks(bool $enabled): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS='.($enabled ? '1' : '0'));
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = '.($enabled ? 'ON' : 'OFF'));
        }
    }

    protected function checksumsForDirectory(string $directory): array
    {
        $checksums = [];

        foreach (File::allFiles($directory) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            if ($relative === 'checksums.json') {
                continue;
            }

            $checksums[$relative] = hash_file('sha256', $file->getPathname());
        }

        ksort($checksums);

        return $checksums;
    }

    protected function zipDirectory(string $directory, string $archivePath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new SnapshotException("Unable to create snapshot archive: {$archivePath}");
        }

        try {
            foreach (File::allFiles($directory) as $file) {
                $zip->addFile($file->getPathname(), str_replace('\\', '/', $file->getRelativePathname()));
            }
        } finally {
            $zip->close();
        }
    }

    protected function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    protected function assertSafePath(?string $path): void
    {
        if ($path === null || $path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, '..')) {
            throw new SnapshotException("Unsafe archive path rejected: {$path}");
        }
    }

    /** Base directory for every path the module writes, from `cms.snapshots.root`. */
    public static function rootDirectory(): string
    {
        $root = (string) Config::get('cms.snapshots.root');

        return rtrim($root !== '' ? $root : storage_path('app/private'), '/\\');
    }

    public static function snapshotsDirectory(): string
    {
        return self::rootDirectory().'/snapshots';
    }

    public static function pendingUploadsDirectory(): string
    {
        return self::snapshotsDirectory().'/uploads';
    }

    public static function downloadsDirectory(): string
    {
        return self::snapshotsDirectory().'/tmp';
    }

    public static function workDirectory(): string
    {
        return self::rootDirectory().'/snapshot-work';
    }

    public static function restoreDirectory(): string
    {
        return self::rootDirectory().'/snapshot-restore';
    }

    public static function uploadsRollbackDirectory(): string
    {
        return self::rootDirectory().'/uploads-restore-backup';
    }

    protected function assertArchiveExists(string $archivePath): void
    {
        if (! is_file($archivePath)) {
            throw new SnapshotException("Snapshot file not found: {$archivePath}");
        }
    }

    protected function assertZipAvailable(): void
    {
        if (! extension_loaded('zip') || ! class_exists(ZipArchive::class)) {
            throw new SnapshotException('The PHP zip extension is required to create or restore snapshots.');
        }
    }

    protected function assertStorageWritable(): void
    {
        $storage = self::rootDirectory();
        $public = Storage::disk('public')->path('');

        File::ensureDirectoryExists($storage);
        File::ensureDirectoryExists($public);

        if (! is_writable($storage) || ! is_writable($public)) {
            throw new SnapshotException('Storage is not writable. Check the snapshots root ('.$storage.') and storage/app/public permissions.');
        }
    }

    protected function safeArchiveName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: 'snapshot';

        return trim($name, '.-') ?: 'snapshot';
    }
}
