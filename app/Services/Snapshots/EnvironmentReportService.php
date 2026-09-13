<?php

namespace App\Services\Snapshots;

use App\Services\Media\MediaHealthService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class EnvironmentReportService
{
    public function __construct(
        protected MediaHealthService $mediaHealth
    ) {}

    public function report(): array
    {
        $publicDisk = Storage::disk('public');
        $publicRoot = $publicDisk->path('');
        $publicLink = public_path('storage');
        $tables = $this->currentSchemaTables();

        return [
            'app' => [
                'version' => Config::get('cms.version'),
                'php_version' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'laravel_version' => App::version(),
                'env' => App::environment(),
                'debug' => (bool) Config::get('app.debug'),
                'url' => Config::get('app.url'),
                'timezone' => Config::get('app.timezone'),
                'commit' => $this->gitCommit(),
            ],
            'database' => [
                'connection' => DB::getDefaultConnection(),
                'driver' => DB::connection()->getDriverName(),
                'host' => Config::get('database.connections.'.DB::getDefaultConnection().'.host'),
                'database' => Config::get('database.connections.'.DB::getDefaultConnection().'.database'),
                'table_count' => count($tables),
                'size' => $this->databaseSize(),
            ],
            'php_limits' => [
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'max_file_uploads' => ini_get('max_file_uploads'),
            ],
            'extensions' => collect(['pdo_mysql', 'mbstring', 'fileinfo', 'gd', 'zip', 'openssl', 'ctype', 'xml'])
                ->mapWithKeys(fn (string $extension) => [$extension => extension_loaded($extension)])
                ->all(),
            'storage' => [
                'path' => $publicRoot,
                'exists' => is_dir($publicRoot),
                'writable' => is_dir($publicRoot) && is_writable($publicRoot),
                'size' => $this->directorySize($publicRoot),
                'files' => $this->directoryFileCount($publicRoot),
                'public_symlink_exists' => is_link($publicLink),
            ],
            'media_health' => $this->safeMediaHealth(),
        ];
    }

    public function currentSchemaTables(): array
    {
        try {
            $schemaName = Schema::getCurrentSchemaName();
            $tables = Schema::getTableListing($schemaName, false);
        } catch (\Throwable) {
            $tables = Schema::getTableListing();
        }

        return collect($tables)
            ->map(fn (string $table): string => str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table)
            ->values()
            ->all();
    }

    protected function databaseSize(): ?int
    {
        $connection = DB::getDefaultConnection();
        $config = Config::get("database.connections.{$connection}", []);
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'mysql') {
                $database = $config['database'] ?? null;

                if (! $database) {
                    return null;
                }

                $row = DB::selectOne(
                    'select coalesce(sum(data_length + index_length), 0) as size from information_schema.tables where table_schema = ?',
                    [$database]
                );

                return $row ? (int) $row->size : null;
            }

            if ($driver === 'sqlite') {
                $database = $config['database'] ?? null;

                return is_string($database) && is_file($database) ? filesize($database) : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    protected function gitCommit(): ?string
    {
        if (! is_dir(base_path('.git'))) {
            return null;
        }

        try {
            $process = new Process(['git', 'rev-parse', '--short=12', 'HEAD'], base_path());
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function safeMediaHealth(): array
    {
        try {
            return $this->mediaHealth->summary();
        } catch (\Throwable $exception) {
            return ['error' => $exception->getMessage()];
        }
    }

    protected function directorySize(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $bytes = 0;

        foreach (File::allFiles($path) as $file) {
            $bytes += $file->getSize();
        }

        return $bytes;
    }

    protected function directoryFileCount(string $path): int
    {
        return is_dir($path) ? count(File::allFiles($path)) : 0;
    }
}
