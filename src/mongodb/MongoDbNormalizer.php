<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\mongodb;

/**
 * Turns a MongoDB command document into the text form of spec 02 §4, with every value replaced by `?`.
 *
 * The input is the tree `CommandStartedEvent::getCommand()` gives: documents as `stdClass`, arrays as PHP
 * lists, values as scalars or BSON objects. Only the sections named in spec 02 §4 are read; `lsid`,
 * `$clusterTime`, `$db`, read preference and everything else stay out. Any object that is not a `stdClass`
 * (`ObjectId`, `UTCDateTime`, `Regex`, …) is a value. Documents and arrays are read at any depth. Returns null
 * when the command is not described or the result would be unsafe or too long (ADR-0004).
 */
final class MongoDbNormalizer
{
    /** Sections in the order of the text form, each as [section name, source key]. */
    private const SECTIONS = [
        'find' => [['filter', 'filter'], ['sort', 'sort'], ['limit', 'limit'], ['skip', 'skip']],
        'count' => [['filter', 'query'], ['limit', 'limit'], ['skip', 'skip']],
        'findAndModify' => [['filter', 'query'], ['update', 'update'], ['sort', 'sort']],
        'aggregate' => [['pipeline', 'pipeline']],
    ];

    public function __construct(private readonly int $maxQueryLength) {}

    public function normalize(string $commandName, object $command): ?string
    {
        $collection = $command->{$commandName} ?? null;
        if ($commandName === 'getMore') {
            $collection = $command->collection ?? null;
        }
        if (!is_string($collection) || $collection === '' || !self::isSafeName($collection)) {
            return null;
        }
        $parts = [$collection];
        switch ($commandName) {
            case 'insert':
                $documents = $command->documents ?? null;
                if (!is_array($documents)) {
                    return null;
                }
                $parts[] = 'n:' . count($documents);
                break;
            case 'update':
            case 'delete':
                $statements = $command->{$commandName === 'update' ? 'updates' : 'deletes'} ?? null;
                if (!is_array($statements) || count($statements) !== 1 || !$statements[0] instanceof \stdClass) {
                    return null;
                }
                $sections = [['filter', 'q']];
                if ($commandName === 'update') {
                    $sections[] = ['update', 'u'];
                }
                $part = $this->sections($statements[0], $sections);
                if ($part === null) {
                    return null;
                }
                array_push($parts, ...$part);
                break;
            case 'distinct':
                // The key is a field name, which is code (spec 02 §4), so it stays as it is.
                $key = $command->key ?? null;
                if (!is_string($key) || !self::isSafeName($key)) {
                    return null;
                }
                $part = $this->sections($command, [['filter', 'query']]);
                if ($part === null) {
                    return null;
                }
                array_push($parts, "key:{$key}", ...$part);
                break;
            case 'getMore':
                break;
            default:
                if (!isset(self::SECTIONS[$commandName])) {
                    return null;
                }
                $part = $this->sections($command, self::SECTIONS[$commandName]);
                if ($part === null) {
                    return null;
                }
                array_push($parts, ...$part);
        }
        $text = implode(' ', $parts);

        return strlen($text) > $this->maxQueryLength ? null : $text;
    }

    /**
     * @param list<array{string, string}> $sections
     *
     * @return list<string>|null
     */
    private function sections(object $source, array $sections): ?array
    {
        $parts = [];
        foreach ($sections as [$name, $key]) {
            if (!property_exists($source, $key)) {
                continue;
            }
            $value = $source->{$key};
            $text = $name === 'pipeline' ? $this->pipeline($value) : $this->value($value);
            if ($text === null) {
                return null;
            }
            $parts[] = $text === '?' ? "{$name}:?" : $name . $text;
        }

        return $parts;
    }

    private function pipeline(mixed $stages): ?string
    {
        if (!is_array($stages)) {
            return null;
        }
        $items = [];
        foreach ($stages as $stage) {
            $text = $stage instanceof \stdClass ? $this->value($stage) : null;
            if ($text === null) {
                return null;
            }
            $items[] = $text;
        }

        return '[' . implode(',', $items) . ']';
    }

    /**
     * `{k:…,…}` for a document, `[…,…]` for an array, `?` for any other value.
     */
    private function value(mixed $value): ?string
    {
        if (!$value instanceof \stdClass && !is_array($value)) {
            return '?';
        }
        $items = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                $text = $this->value($item);
                if ($text === null) {
                    return null;
                }
                $items[] = $text;
            }

            return '[' . implode(',', $items) . ']';
        }
        foreach (get_object_vars($value) as $key => $item) {
            $key = (string) $key;
            if (!self::isSafeName($key)) {
                return null;
            }
            $text = $key === '$in' || $key === '$nin' ? $this->inOperand($item) : $this->value($item);
            if ($text === null) {
                return null;
            }
            $items[] = "{$key}:{$text}";
        }

        return '{' . implode(',', $items) . '}';
    }

    /**
     * The value of `$in` or `$nin` (ADR-0011): a list of values becomes `[?,...]`, and so does the array in the
     * aggregation form `$in: [expression, array]`. Anything else follows {@see value()}.
     */
    private function inOperand(mixed $item): ?string
    {
        if (self::isValueList($item)) {
            return '[?,...]';
        }
        if (is_array($item) && count($item) === 2 && array_is_list($item) && is_array($item[1])) {
            $expression = $this->value($item[0]);
            $array = self::isValueList($item[1]) ? '[?,...]' : $this->value($item[1]);

            return $expression === null || $array === null ? null : "[{$expression},{$array}]";
        }

        return $this->value($item);
    }

    /**
     * At least two values, none of them a document, an array, or a string starting with `$`, which is a field
     * path or a variable in an aggregation expression rather than a value.
     */
    private static function isValueList(mixed $item): bool
    {
        if (!is_array($item) || count($item) < 2) {
            return false;
        }
        foreach ($item as $element) {
            if ($element instanceof \stdClass || is_array($element) || (is_string($element) && str_starts_with($element, '$'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Names are code (spec 02 §4), but an empty name, one that is not UTF-8, or one with a character the
     * text form uses as syntax, any whitespace or a control character makes the text ambiguous.
     */
    private static function isSafeName(string $name): bool
    {
        return $name !== '' && preg_match('/[\s\p{Z}\p{Cc}{}\[\],:]/u', $name) === 0;
    }
}
