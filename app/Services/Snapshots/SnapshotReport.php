<?php

namespace App\Services\Snapshots;

/**
 * Result of inspecting a snapshot archive without touching the destination.
 *
 * The report is what both the CLI and the admin UI show before asking for
 * confirmation, and what {@see SnapshotService::restore()} evaluates against
 * the risk keys the operator accepted.
 */
final class SnapshotReport
{
    /**
     * @param  array<int, array{name: string, file: string, rows?: int}>  $tables  Tables that will be restored.
     * @param  array<int, SnapshotRisk>  $risks
     */
    public function __construct(
        public readonly array $manifest,
        public readonly array $tables,
        public readonly array $risks,
        public readonly array $destination,
    ) {}

    /** @return array<int, SnapshotRisk> */
    public function blocking(): array
    {
        return array_values(array_filter($this->risks, fn (SnapshotRisk $risk): bool => $risk->isBlocking()));
    }

    /** @return array<int, SnapshotRisk> */
    public function warnings(): array
    {
        return array_values(array_filter($this->risks, fn (SnapshotRisk $risk): bool => $risk->isWarning()));
    }

    /** @return array<int, SnapshotRisk> */
    public function info(): array
    {
        return array_values(array_filter($this->risks, fn (SnapshotRisk $risk): bool => $risk->severity === SnapshotRisk::INFO));
    }

    /**
     * Warnings the operator has not accepted yet.
     *
     * @param  array<int, string>  $accepted
     * @return array<int, SnapshotRisk>
     */
    public function pendingWarnings(array $accepted): array
    {
        return array_values(array_filter(
            $this->warnings(),
            fn (SnapshotRisk $risk): bool => ! in_array($risk->key, $accepted, true)
        ));
    }

    /** @param array<int, string> $accepted */
    public function isRestorable(array $accepted): bool
    {
        return $this->blocking() === [] && $this->pendingWarnings($accepted) === [];
    }

    /** @param array<int, string> $accepted */
    public function assertRestorable(array $accepted): void
    {
        if ($blocking = $this->blocking()) {
            throw new SnapshotException('Snapshot cannot be restored: '.implode(' ', array_map(
                fn (SnapshotRisk $risk): string => $risk->message,
                $blocking
            )));
        }

        if ($pending = $this->pendingWarnings($accepted)) {
            throw new SnapshotException('Snapshot has unaccepted risks: '.implode(', ', array_map(
                fn (SnapshotRisk $risk): string => $risk->key,
                $pending
            )));
        }
    }

    public function summary(): array
    {
        return [
            'format_version' => (int) ($this->manifest['version'] ?? 0),
            'version' => $this->manifest['app']['version'] ?? null,
            'created_at' => $this->manifest['created_at'] ?? null,
            'origin_url' => $this->manifest['app']['url'] ?? null,
            'origin_commit' => $this->manifest['app']['commit'] ?? null,
            'laravel_version' => $this->manifest['app']['laravel_version'] ?? null,
            'driver' => $this->manifest['database']['driver'] ?? null,
            'tables' => count($this->tables),
            'rows' => array_sum(array_map(fn (array $table): int => (int) ($table['rows'] ?? 0), $this->tables)),
            'uploads_files' => (int) ($this->manifest['uploads']['files'] ?? 0),
            'uploads_bytes' => (int) ($this->manifest['uploads']['bytes'] ?? 0),
        ];
    }

    public function toArray(): array
    {
        return [
            'manifest' => $this->manifest,
            'tables' => $this->tables,
            'risks' => array_map(fn (SnapshotRisk $risk): array => $risk->toArray(), $this->risks),
            'destination' => $this->destination,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            is_array($data['manifest'] ?? null) ? $data['manifest'] : [],
            is_array($data['tables'] ?? null) ? $data['tables'] : [],
            array_map(fn (array $risk): SnapshotRisk => SnapshotRisk::fromArray($risk), $data['risks'] ?? []),
            is_array($data['destination'] ?? null) ? $data['destination'] : [],
        );
    }
}
