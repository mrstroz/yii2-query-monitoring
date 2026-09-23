<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\support;

/**
 * Decoding of the JSON Lines format the file adapter writes (spec 03 §3).
 *
 * A whole file always ends with a newline; a content that does not is a torn write, which is a failure of
 * the adapter, not of the decoding, so it is refused here instead of producing a half batch.
 */
final class JsonLines
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function decode(string $content): array
    {
        if (!str_ends_with($content, "\n")) {
            throw new \InvalidArgumentException('JSON Lines content does not end with a newline');
        }

        $batches = [];
        foreach (explode("\n", rtrim($content, "\n")) as $line) {
            $batch = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($batch)) {
                throw new \InvalidArgumentException('a JSON Lines line is not an object');
            }
            /** @var array<string, mixed> $batch */
            $batches[] = $batch;
        }

        return $batches;
    }
}
