<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\support;

/**
 * The length limit of a normalised `query`, shared by the SQL and MongoDB normalisers (spec 02 §4).
 */
final class QueryText
{
    public const ELLIPSIS = '…';

    /**
     * @throws \InvalidArgumentException when `$maxLength` cannot hold {@see self::ELLIPSIS}
     */
    public static function assertMaxLength(int $maxLength): void
    {
        if ($maxLength < strlen(self::ELLIPSIS)) {
            throw new \InvalidArgumentException('maxQueryLength must be at least ' . strlen(self::ELLIPSIS) . ' bytes.');
        }
    }

    /**
     * Cuts `$query` to `$maxLength` bytes including {@see self::ELLIPSIS}, on a UTF-8 character boundary.
     */
    public static function truncate(string $query, int $maxLength): string
    {
        if (strlen($query) <= $maxLength) {
            return $query;
        }
        $cut = $maxLength - strlen(self::ELLIPSIS);
        while ($cut > 0 && (ord($query[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }

        return substr($query, 0, $cut) . self::ELLIPSIS;
    }
}
