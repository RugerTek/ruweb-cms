<?php

namespace App\Services\Snapshots;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Keeps the snapshot storage footprint bounded.
 *
 * Everything the snapshot module writes to disk is transient except the
 * archives the operator asked for by name. Pending uploads, work folders,
 * interrupted restores and the automatic pre-restore backups all grow
 * silently otherwise, so this class knows how to measure and prune them.
 */
class SnapshotHousekeeping
{
    public const PENDING_TOKEN_PATTERN = '/^[A-Za-z0-9]{40}$/';

    public function __construct(
        protected SnapshotService $snapshots
    ) {}

    /**
     * Store an uploaded archive so it can be reviewed before restoring.
     * Returns the opaque token used to address it later.
     */
    public function storePending(string $sourcePath, SnapshotReport $report, ?string $originalName = null): string
    {
        $directory = SnapshotService::pendingUploadsDirectory();
        File::ensureDirectoryExists($directory);

        $token = Str::random(40);
        File::move($sourcePath, $this->pendingArchivePath($token));
        File::put($this->pendingReportPath($token), json_encode([
            'original_name' => $originalName,
            'stored_at' => Carbon::now()->toIso8601String(),
            'report' => $report->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $token;
    }

    public function pendingArchivePath(string $token): string
    {
        $this->assertToken($token);

        return SnapshotService::pendingUploadsDirectory().'/'.$token.'.appbackup';
    }

    public function pendingReportPath(string $token): string
    {
        $this->assertToken($token);

        return SnapshotService::pendingUploadsDirectory().'/'.$token.'.json';
    }

    /**
     * @return array{token: string, original_name: ?string, stored_at: ?string, size: int, report: SnapshotReport}|null
     */
    public function pending(string $token): ?array
    {
        $archive = $this->pendingArchivePath($token);
        $reportPath = $this->pendingReportPath($token);

        if (! is_file($archive) || ! is_file($reportPath)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($reportPath), true);

        if (! is_array($data) || ! is_array($data['report'] ?? null)) {
            return null;
        }

        return [
            'token' => $token,
            'original_name' => $data['original_name'] ?? null,
            'stored_at' => $data['stored_at'] ?? null,
            'size' => (int) filesize($archive),
            'report' => SnapshotReport::fromArray($data['report']),
        ];
    }

    /** @return array<int, array{token: string, original_name: ?string, stored_at: ?string, size: int, report: SnapshotReport}> */
    public function pendingList(): array
    {
        $directory = SnapshotService::pendingUploadsDirectory();

        if (! is_dir($directory)) {
            return [];
        }

        return collect(File::files($directory))
            ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'appbackup')
            ->map(fn (SplFileInfo $file): string => $file->getFilenameWithoutExtension())
            ->filter(fn (string $token): bool => preg_match(self::PENDING_TOKEN_PATTERN, $token) === 1)
            ->map(fn (string $token): ?array => $this->pending($token))
            ->filter()
            ->sortByDesc('stored_at')
            ->values()
            ->all();
    }

    public function discardPending(string $token): void
    {
        File::delete([$this->pendingArchivePath($token), $this->pendingReportPath($token)]);
    }

    /**
     * Disk usage per transient category, so the UI can show what a prune
     * would reclaim before the operator clicks it.
     *
     * @return array<string, array{files: int, bytes: int}>
     */
    public function usage(): array
    {
        return [
            'pending_uploads' => $this->measure(File::isDirectory(SnapshotService::pendingUploadsDirectory()) ? File::files(SnapshotService::pendingUploadsDirectory()) : []),
            'pre_restore_backups' => $this->measure($this->preRestoreBackups()),
            'download_temp' => $this->measure(File::isDirectory(SnapshotService::downloadsDirectory()) ? File::allFiles(SnapshotService::downloadsDirectory()) : []),
            'work_dirs' => $this->measure(array_merge(
                File::isDirectory(SnapshotService::workDirectory()) ? File::allFiles(SnapshotService::workDirectory()) : [],
                File::isDirectory(SnapshotService::restoreDirectory()) ? File::allFiles(SnapshotService::restoreDirectory()) : [],
                File::isDirectory(SnapshotService::uploadsRollbackDirectory()) ? File::allFiles(SnapshotService::uploadsRollbackDirectory()) : [],
            )),
        ];
    }

    /**
     * Remove transient data. Pending uploads and work folders older than
     * `$staleHours` go away; only the newest `$keepPreRestore` automatic
     * pre-restore backups survive.
     *
     * @return array<string, array{files: int, bytes: int}> What was removed, per category.
     */
    public function prune(int $staleHours = 24, int $keepPreRestore = 3): array
    {
        $cutoff = Carbon::now()->subHours(max(0, $staleHours))->getTimestamp();
        $removed = [];

        $removed['pending_uploads'] = $this->removePendingOlderThan($cutoff);
        $removed['pre_restore_backups'] = $this->removeOldPreRestoreBackups(max(0, $keepPreRestore));
        $removed['download_temp'] = $this->removeStaleTree(SnapshotService::downloadsDirectory(), $cutoff);
        $removed['work_dirs'] = $this->sum([
            $this->removeStaleTree(SnapshotService::workDirectory(), $cutoff),
            $this->removeStaleTree(SnapshotService::restoreDirectory(), $cutoff),
            $this->removeStaleTree(SnapshotService::uploadsRollbackDirectory(), $cutoff),
        ]);

        return $removed;
    }

    /** @return array<int, SplFileInfo> */
    protected function preRestoreBackups(): array
    {
        $directory = SnapshotService::snapshotsDirectory();

        if (! is_dir($directory)) {
            return [];
        }

        return collect(File::files($directory))
            ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'appbackup' && str_starts_with($file->getFilename(), 'pre-restore-'))
            ->sortByDesc(fn (SplFileInfo $file): int => $file->getMTime())
            ->values()
            ->all();
    }

    protected function removePendingOlderThan(int $cutoff): array
    {
        $stats = ['files' => 0, 'bytes' => 0];

        foreach ($this->pendingList() as $pending) {
            $archive = $this->pendingArchivePath($pending['token']);

            if (filemtime($archive) > $cutoff) {
                continue;
            }

            $stats['files']++;
            $stats['bytes'] += $pending['size'];
            $this->discardPending($pending['token']);
        }

        return $stats;
    }

    protected function removeOldPreRestoreBackups(int $keep): array
    {
        $stats = ['files' => 0, 'bytes' => 0];

        foreach (array_slice($this->preRestoreBackups(), $keep) as $file) {
            $stats['files']++;
            $stats['bytes'] += $file->getSize();
            File::delete($file->getPathname());
        }

        return $stats;
    }

    /**
     * Delete top-level entries of `$directory` whose modification time is
     * older than `$cutoff`. Entries are whole work folders or temp archives,
     * so removing them as units never leaves a half-deleted tree behind.
     */
    protected function removeStaleTree(string $directory, int $cutoff): array
    {
        $stats = ['files' => 0, 'bytes' => 0];

        if (! is_dir($directory)) {
            return $stats;
        }

        foreach (File::directories($directory) as $child) {
            if (filemtime($child) > $cutoff) {
                continue;
            }

            $measured = $this->measure(File::allFiles($child));
            $stats['files'] += $measured['files'];
            $stats['bytes'] += $measured['bytes'];
            File::deleteDirectory($child);
        }

        foreach (File::files($directory) as $file) {
            if ($file->getMTime() > $cutoff) {
                continue;
            }

            $stats['files']++;
            $stats['bytes'] += $file->getSize();
            File::delete($file->getPathname());
        }

        return $stats;
    }

    /** @param iterable<SplFileInfo> $files */
    protected function measure(iterable $files): array
    {
        $stats = ['files' => 0, 'bytes' => 0];

        foreach ($files as $file) {
            $stats['files']++;
            $stats['bytes'] += $file->getSize();
        }

        return $stats;
    }

    /** @param array<int, array{files: int, bytes: int}> $parts */
    protected function sum(array $parts): array
    {
        return [
            'files' => array_sum(array_column($parts, 'files')),
            'bytes' => array_sum(array_column($parts, 'bytes')),
        ];
    }

    protected function assertToken(string $token): void
    {
        if (preg_match(self::PENDING_TOKEN_PATTERN, $token) !== 1) {
            throw new SnapshotException('Invalid pending snapshot token.');
        }
    }
}
