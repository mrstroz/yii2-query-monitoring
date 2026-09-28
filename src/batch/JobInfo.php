<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\batch;

use mrstroz\querymonitoring\support\QueryText;

/**
 * Metadata of one job attempt, the header field `job` (spec 02 §1).
 *
 * Only what identifies the job, never its payload, arguments or exception. {@see self::create()} applies the limits;
 * the constructor stores values as given.
 */
final class JobInfo
{
    /** Longest `name`, `queue` and `message_id` in bytes, ellipsis included. */
    public const MAX_LENGTH = 255;

    /** `name` of a job whose name is empty. */
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $name,
        public readonly ?string $queue = null,
        public readonly ?string $messageId = null,
        public readonly ?int $attempt = null,
    ) {}

    /**
     * An empty `$name` becomes {@see self::UNKNOWN}, text is cut to {@see self::MAX_LENGTH} bytes with `…`
     * on a UTF-8 boundary, a numeric message id becomes text and an attempt below 1 becomes null.
     */
    public static function create(string $name, ?string $queue = null, string|int|null $messageId = null, ?int $attempt = null): self
    {
        return new self(
            QueryText::truncate(self::name($name), self::MAX_LENGTH),
            $queue === null ? null : QueryText::truncate($queue, self::MAX_LENGTH),
            $messageId === null ? null : QueryText::truncate((string) $messageId, self::MAX_LENGTH),
            $attempt !== null && $attempt >= 1 ? $attempt : null,
        );
    }

    /**
     * The name as matched by `excludedRoutes['job']`: before truncation, with an empty name as {@see self::UNKNOWN}.
     */
    public static function name(string $name): string
    {
        return $name === '' ? self::UNKNOWN : $name;
    }

    /**
     * Keys in spec 02 §1 order, always all four.
     *
     * @return array{name: string, queue: ?string, message_id: ?string, attempt: ?int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'queue' => $this->queue,
            'message_id' => $this->messageId,
            'attempt' => $this->attempt,
        ];
    }
}
