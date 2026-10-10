<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\Media\MediaUsage;
use Illuminate\Console\Command;

class CmsAuditOrphanedMediaCommand extends Command
{
    protected $signature = 'cms:media:audit-orphans {--delete : Delete orphaned media records and files}';

    protected $description = 'Audit media not referenced by content, localized settings or media relations';

    public function handle(): int
    {
        $usage = app(MediaUsage::class);
        $orphans = Media::query()->latest()->get()
            ->filter(fn (Media $media) => $usage->references($media) === []);

        if ($orphans->isEmpty()) {
            $this->info('No orphaned media found.');

            return Command::SUCCESS;
        }

        $this->warn("Found {$orphans->count()} orphaned media items:");

        $rows = $orphans->map(fn ($item) => [
            $item->id,
            $item->original_filename,
            $item->mime_type,
            $item->path,
        ])->all();

        $this->table(['ID', 'Filename', 'MIME', 'Path'], $rows);

        if (! $this->option('delete')) {
            $this->line('Run again with --delete to remove records and files.');

            return Command::SUCCESS;
        }

        foreach ($orphans as $orphan) {
            $orphan->delete();
        }

        $this->info("Deleted {$orphans->count()} orphaned media records.");

        return Command::SUCCESS;
    }
}
