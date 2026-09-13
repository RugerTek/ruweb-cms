<?php

namespace App\Services\Snapshots;

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
        'cache',
        'cache_locks',
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
        'settings',
        'pages',
        'posts',
        'media',
        'taxonomies',
        'menus',
        'menu_items',
        'mediables',
        'taxables',
    ];

    public function __construct(
        protected EnvironmentReportService $environment
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
                ],
                'uploads' => [
                    'logical_root' => 'public',
                    'files' => $uploadStats['files'],
                    'bytes' => $uploadStats['bytes'],
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
     */
    public function restore(string $archivePath, array $accepted = []): array
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

            $preRestore = $this->create('pre-restore-'.now()->format('Ymd-His'));

            Artisan::call('down', ['--retry' => 60]);

            try {
                $this->restoreDatabase($extractDir, $tables);
                $this->replaceUploads($extractDir.'/uploads');
                Artisan::call('storage:link');
                Artisan::call('optimize:clear');
            } finally {
                Artisan::call('up');
            }

            return [
                'pre_restore_backup' => $preRestore,
                'tables' => count($tables),
                'uploads_files' => (int) ($manifest['uploads']['files'] ?? 0),
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
            fn (array $table): bool => ! in_array($table['name'], self::RESTORE_SKIPPED_TABLES, true)
        ));

        $destination = [
            'driver' => DB::connection()->getDriverName(),
            'url' => Config::get('app.url'),
            'laravel_version' => App::version(),
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
                "Snapshot was taken from {$originUrl}; this installation runs at {$destination['url']}.",
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
                        fwrite($handle, json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
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

    protected function restoreDatabase(string $extractDir, array $tables): void
    {
        $this->setForeignKeyChecks(false);
        DB::beginTransaction();

        try {
            foreach (array_reverse($tables) as $table) {
                DB::table($table['name'])->delete();
            }

            foreach ($tables as $table) {
                $this->importTable($extractDir.'/'.$table['file'], $table['name']);
            }

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        } finally {
            $this->setForeignKeyChecks(true);
        }
    }

    protected function importTable(string $path, string $table): void
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

                $batch[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

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

    public static function snapshotsDirectory(): string
    {
        return storage_path('app/private/snapshots');
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
        return storage_path('app/private/snapshot-work');
    }

    public static function restoreDirectory(): string
    {
        return storage_path('app/private/snapshot-restore');
    }

    public static function uploadsRollbackDirectory(): string
    {
        return storage_path('app/private/uploads-restore-backup');
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
        $storage = storage_path('app/private');
        $public = Storage::disk('public')->path('');

        File::ensureDirectoryExists($storage);
        File::ensureDirectoryExists($public);

        if (! is_writable($storage) || ! is_writable($public)) {
            throw new SnapshotException('Storage is not writable. Check storage/app and storage/app/public permissions.');
        }
    }

    protected function safeArchiveName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: 'snapshot';

        return trim($name, '.-') ?: 'snapshot';
    }
}
