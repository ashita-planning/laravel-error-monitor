<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A log file an external package has made available to the core.
 *
 * The file is always a readable local path. Whatever it took to get there -
 * SSH, FTP, an API, a signed URL - is the adapter's business, and none of that
 * knowledge belongs here.
 *
 * A stream resource or a factory closure is deliberately not accepted: neither
 * serializes, both make the DTO awkward to build in a test, and a local path
 * covers every case the package has today. A `ReadableLogStream` contract can
 * be added later if a source ever genuinely cannot produce a file.
 *
 * Compression is reported rather than resolved. The bundled parsers stream
 * `.gz` through `gzopen`, so an adapter that decompresses first is doing work
 * the core would have done for free.
 */
final readonly class CollectedLogFileData
{
    /**
     * @param  string  $source  Source key a parser claims the file by, e.g. `apache_access`.
     * @param  string  $path  Readable local path.
     * @param  DateTimeImmutable  $targetDate  Day the file holds entries for.
     * @param  string  $fileHash  Adapter's identity for this exact content.
     * @param  bool  $compressed  Whether the file is gzip compressed.
     * @param  array<string, mixed>  $metadata  Free-form notes: `domain`, `server_identifier`, ...
     *
     * @throws InvalidArgumentException When an externally supplied value is unusable.
     */
    public function __construct(
        public string $source,
        public string $path,
        public DateTimeImmutable $targetDate,
        public string $fileHash,
        public bool $compressed = false,
        public array $metadata = [],
    ) {
        if (trim($source) === '') {
            throw new InvalidArgumentException('A collected log file requires a non-empty source.');
        }

        if (trim($path) === '') {
            throw new InvalidArgumentException('A collected log file requires a path.');
        }

        if (trim($fileHash) === '') {
            throw new InvalidArgumentException('A collected log file requires a file hash.');
        }
    }

    /**
     * Canonical hash of a file's contents.
     *
     * Offered so every adapter identifies the same file the same way. The core
     * does not recompute what an adapter reports: verifying would mean reading
     * every byte of every log a second time, and the hash is an identity claim
     * used for deduplication rather than an integrity guarantee.
     */
    public static function hashOf(string $path): string
    {
        $hash = @hash_file('sha256', $path);

        return $hash === false ? '' : $hash;
    }

    /** Key that makes the same file collected twice recognisable. */
    public function identity(): string
    {
        return $this->source.'|'.$this->targetDate->format('Y-m-d').'|'.$this->fileHash;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'path' => $this->path,
            'target_date' => $this->targetDate->format('Y-m-d'),
            'file_hash' => $this->fileHash,
            'compressed' => $this->compressed,
            'metadata' => $this->metadata,
        ];
    }
}
