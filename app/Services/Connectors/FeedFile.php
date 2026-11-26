<?php

namespace App\Services\Connectors;

use App\Connectors\ConnectorRegistry;
use App\Connectors\FeedResult;
use App\Models\Connector;
use App\Support\SiteUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The generated document of a feed connector (PLAN.md §4.7): `feeds/{key}.{extension}` on the private `local` disk,
 * built by walking the source through check() and write(), written to a temporary file in the same folder and
 * renamed over the previous one, so a reader never sees half a document. Next to it, `{document}.origin` keeps the
 * SiteUrl::origin() the links were built with. Rebuilt when missing or stale (ConnectorOperations::feedIsStale():
 * older than FEED_TTL_SECONDS, after touchFeeds(), or built for another origin because APP_URL or the domain
 * changed); one build at a time per connector, while other requests keep serving the previous file.
 */
final class FeedFile
{
    public const DISK = 'local';

    public const DIRECTORY = 'feeds';

    /** Seconds a build may hold the lock, and seconds a request without any file waits for a running build. */
    private const LOCK_SECONDS = 300;

    private const WAIT_SECONDS = 60;

    /** Suffix of the sibling file holding the origin a document was built for. */
    private const ORIGIN_SUFFIX = '.origin';

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly ConnectorOperations $operations,
    ) {}

    /** Absolute path of the connector's document (it may not exist yet). */
    public function path(string $key): string
    {
        $extension = $this->registry->feed($key)->extension();

        return Storage::disk(self::DISK)->path(self::DIRECTORY.'/'.$key.'.'.$extension);
    }

    /** Path of an up-to-date document, building it first when it is missing or stale. */
    public function fresh(Connector $connector): string
    {
        $path = $this->path($connector->key);
        if (! $this->isStale($path)) {
            return $path;
        }

        $lock = Cache::lock('connectors.feed_file.'.$connector->key, self::LOCK_SECONDS);
        if ($lock->get()) {
            try {
                if ($this->isStale($path)) {
                    $this->generate($connector);
                }
            } catch (Throwable $e) {
                if (! is_file($path)) {
                    throw $e;
                }
                // A failed rebuild keeps serving the last good document; the error still reaches the log.
                report($e);
            } finally {
                $lock->release();
            }

            return $path;
        }

        if (is_file($path)) {
            // Another request is rebuilding: the previous document is still a valid answer.
            return $path;
        }

        $lock->block(self::WAIT_SECONDS, function () use ($connector, $path) {
            if ($this->isStale($path)) {
                $this->generate($connector);
            }
        });

        return $path;
    }

    /**
     * Build the document now and store the totals on the row (ConnectorOperations::recordCounts()).
     *
     * @throws RuntimeException when the temporary file cannot be written or renamed
     */
    public function generate(Connector $connector): FeedResult
    {
        $definition = $this->registry->feed($connector->key);
        $source = $this->registry->source($definition->source());
        $settings = $this->registry->settings($connector->key);
        $origin = SiteUrl::origin();
        $path = $this->path($connector->key);
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the feed directory '.$directory.'.');
        }

        $temporary = $path.'.'.Str::random(12).'.tmp';
        $stream = fopen($temporary, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Cannot write the feed file '.$temporary.'.');
        }

        $included = 0;
        $skipped = 0;
        $warnings = [];
        $items = (function () use ($source, $definition, $settings, &$included, &$skipped, &$warnings) {
            if (! $source->available()) {
                return;
            }
            foreach ($source->items() as $item) {
                $itemWarnings = $definition->check($item, $settings);
                array_push($warnings, ...$itemWarnings);
                if (array_filter($itemWarnings, fn ($warning): bool => $warning->skips) !== []) {
                    $skipped++;

                    continue;
                }
                $included++;
                yield $item;
            }
        })();

        try {
            $definition->write($items, $settings, $stream);
            fclose($stream);
            $stream = null;
            $this->replace($temporary, $path);
        } catch (Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($temporary);

            throw $e;
        }
        // Written after the rename: a reader in between sees the old origin and rebuilds once more, never the reverse.
        $this->recordOrigin($path, $origin);

        $result = new FeedResult($included, $skipped, [], $warnings);
        $this->operations->recordCounts($connector, $result);

        return $result;
    }

    private function isStale(string $path): bool
    {
        clearstatcache(true, $path);
        $generatedAt = is_file($path) ? filemtime($path) : false;

        return $generatedAt === false || $this->operations->feedIsStale($generatedAt, $this->builtForOrigin($path));
    }

    /** Origin the document at $path was built for; '' when unknown (a file from before this record), so it is rebuilt. */
    private function builtForOrigin(string $path): string
    {
        $file = $path.self::ORIGIN_SUFFIX;
        clearstatcache(true, $file);
        $origin = is_file($file) ? @file_get_contents($file) : false;

        return $origin === false ? '' : $origin;
    }

    /** @throws RuntimeException when the origin file cannot be written */
    private function recordOrigin(string $path, string $origin): void
    {
        if (@file_put_contents($path.self::ORIGIN_SUFFIX, $origin, LOCK_EX) !== strlen($origin)) {
            throw new RuntimeException('Cannot write the feed origin file '.$path.self::ORIGIN_SUFFIX.'.');
        }
    }

    /** Atomic on the same filesystem; retried because Windows refuses to replace a file another process is reading. */
    private function replace(string $temporary, string $path): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            if (@rename($temporary, $path)) {
                return;
            }
            usleep(100_000 * $attempt);
        }

        throw new RuntimeException('Cannot replace the feed file '.$path.'.');
    }
}
