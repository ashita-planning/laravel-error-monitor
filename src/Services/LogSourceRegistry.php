<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Services;

use Apkk\LaravelErrorMonitor\Contracts\ServerLogSource;
use InvalidArgumentException;

/**
 * The external log sources an installation has available.
 *
 * An empty registry is the normal state: the package ships no adapter and works
 * completely without one. Registering is additive, and a second source claiming
 * an id that is already taken is refused rather than silently replacing the
 * first - two adapters answering to one name would make it impossible to say
 * which of them produced a given file.
 */
final class LogSourceRegistry
{
    /** @var array<string, ServerLogSource> */
    private array $sources = [];

    /**
     * @param  array<int, ServerLogSource>  $sources
     *
     * @throws InvalidArgumentException When two sources share an id.
     */
    public function __construct(array $sources = [])
    {
        foreach ($sources as $source) {
            $this->register($source);
        }
    }

    /** @throws InvalidArgumentException When the id is empty or already taken. */
    public function register(ServerLogSource $source): void
    {
        $id = trim($source->id());

        if ($id === '') {
            throw new InvalidArgumentException('A server log source requires a non-empty id.');
        }

        if (isset($this->sources[$id])) {
            throw new InvalidArgumentException(sprintf('A server log source with id [%s] is already registered.', $id));
        }

        $this->sources[$id] = $source;
    }

    public function has(string $id): bool
    {
        return isset($this->sources[trim($id)]);
    }

    public function get(string $id): ?ServerLogSource
    {
        return $this->sources[trim($id)] ?? null;
    }

    /** @return array<string, ServerLogSource> */
    public function all(): array
    {
        return $this->sources;
    }

    /** @return array<int, string> */
    public function ids(): array
    {
        return array_keys($this->sources);
    }

    public function isEmpty(): bool
    {
        return $this->sources === [];
    }
}
