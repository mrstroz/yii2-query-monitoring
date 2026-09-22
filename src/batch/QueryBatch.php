<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\batch;

/**
 * Header plus a flat list of entries, the unit handed to an adapter (spec 02 §1).
 *
 * Every value is passed in explicitly: the batch neither generates ids nor reads the clock.
 */
final class QueryBatch
{
    public const VERSION = 1;

    /**
     * Flags for every JSON produced by the package; the collector counts bytes with the same flags.
     * Invalid UTF-8 is substituted rather than failing, so a stray byte in a value cannot lose the batch.
     */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * @param list<QueryEntry> $queries in order of completion
     */
    public function __construct(
        public readonly string $app,
        public readonly BatchType $type,
        public readonly string $id,
        public readonly int $seq,
        public readonly ?string $module,
        public readonly ?string $controller,
        public readonly ?string $action,
        public readonly \DateTimeImmutable $ts,
        public readonly string $host,
        public readonly int $dropped,
        public readonly array $queries,
    ) {}

    /**
     * Keys in spec 02 §1 order. `ts` is converted to UTC and formatted as `Y-m-d\TH:i:s.v\Z`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'app' => $this->app,
            'type' => $this->type->value,
            'id' => $this->id,
            'seq' => $this->seq,
            'module' => $this->module,
            'controller' => $this->controller,
            'action' => $this->action,
            'ts' => self::formatTs($this->ts),
            'host' => $this->host,
            'dropped' => $this->dropped,
            'queries' => array_map(static fn(QueryEntry $entry): array => $entry->toArray(), $this->queries),
        ];
    }

    /**
     * One-line JSON with {@see self::JSON_FLAGS}.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), self::JSON_FLAGS);
    }

    /**
     * `ts` as written to the batch: UTC with milliseconds, always 24 characters.
     */
    public static function formatTs(\DateTimeImmutable $ts): string
    {
        return $ts->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');
    }
}
