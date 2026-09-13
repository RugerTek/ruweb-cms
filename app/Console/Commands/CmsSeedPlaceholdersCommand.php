<?php

namespace App\Console\Commands;

use App\Services\Media\PlaceholderMediaInstaller;
use Illuminate\Console\Command;
use Throwable;

class CmsSeedPlaceholdersCommand extends Command
{
    protected $signature = 'cms:placeholders {--force : Rebuild the placeholders even when their files are present}';
    protected $description = 'Seed the starter placeholder images into the media library, restoring any missing files.';

    public function __construct(
        protected PlaceholderMediaInstaller $installer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $results = $this->installer->install((bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error('  ✗ ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($results as $key => $result) {
            $this->line(match ($result) {
                'created' => "  ✓ {$key}: created",
                'restored' => "  ✓ {$key}: files were missing, restored",
                'regenerated' => "  ✓ {$key}: rebuilt",
                default => "  · {$key}: already present",
            });
        }

        return self::SUCCESS;
    }
}
