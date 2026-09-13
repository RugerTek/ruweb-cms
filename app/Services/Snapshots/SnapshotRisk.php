<?php

namespace App\Services\Snapshots;

/**
 * One finding produced while inspecting a snapshot against the current
 * installation. Blocking risks can never be accepted; warnings must be
 * accepted explicitly by key before a restore runs; info entries are
 * purely descriptive.
 */
final class SnapshotRisk
{
    public const BLOCKING = 'blocking';

    public const WARNING = 'warning';

    public const INFO = 'info';

    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        public readonly string $message,
        public readonly array $details = [],
    ) {}

    public static function blocking(string $key, string $message, array $details = []): self
    {
        return new self($key, self::BLOCKING, $message, $details);
    }

    public static function warning(string $key, string $message, array $details = []): self
    {
        return new self($key, self::WARNING, $message, $details);
    }

    public static function info(string $key, string $message, array $details = []): self
    {
        return new self($key, self::INFO, $message, $details);
    }

    public function isBlocking(): bool
    {
        return $this->severity === self::BLOCKING;
    }

    public function isWarning(): bool
    {
        return $this->severity === self::WARNING;
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'severity' => $this->severity,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['key'],
            (string) $data['severity'],
            (string) $data['message'],
            is_array($data['details'] ?? null) ? $data['details'] : [],
        );
    }
}
