<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\DTO;

use Apkk\LaravelErrorMonitor\Contracts\ServerLogSource;
use DateTimeImmutable;

/**
 * A log file a collector found, wherever it found it.
 *
 * The last four attributes are only filled in when the file arrived through a
 * {@see ServerLogSource}: a file discovered
 * on the local disk has no target date to declare and no adapter to describe
 * itself to. Parsers do not read them - they exist so a caller can tell two
 * copies of the same log apart and say where one came from.
 */
final readonly class LogFileData
{
    /**
     * @param  string  $path  Readable local path.
     * @param  string  $source  Source key a parser claims the file by.
     * @param  DateTimeImmutable|null  $targetDate  Day an external source says the file covers.
     * @param  string|null  $fileHash  Identity an external source gave the contents.
     * @param  bool  $compressed  Whether an external source reported it as gzip.
     * @param  array<string, mixed>  $metadata  Adapter notes: `domain`, `server_identifier`, ...
     */
    public function __construct(
        public string $path,
        public string $source,
        public ?DateTimeImmutable $modifiedAt = null,
        public ?int $size = null,
        public ?DateTimeImmutable $targetDate = null,
        public ?string $fileHash = null,
        public bool $compressed = false,
        public array $metadata = [],
    ) {}

    /** File name without the directory part. */
    public function filename(): string
    {
        return basename($this->path);
    }

    /**
     * Key that makes the same file recognisable across runs.
     *
     * Null unless an external source supplied a hash and a target date: a
     * locally discovered file is identified by its path, and nothing else
     * claims to have seen it before.
     */
    public function identity(): ?string
    {
        if ($this->fileHash === null || $this->targetDate === null) {
            return null;
        }

        return $this->source.'|'.$this->targetDate->format('Y-m-d').'|'.$this->fileHash;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'source' => $this->source,
            'modified_at' => $this->modifiedAt?->format(DATE_ATOM),
            'size' => $this->size,
            'target_date' => $this->targetDate?->format('Y-m-d'),
            'file_hash' => $this->fileHash,
            'compressed' => $this->compressed,
            'metadata' => $this->metadata,
        ];
    }
}
