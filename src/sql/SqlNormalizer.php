<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\sql;

use mrstroz\querymonitoring\support\QueryText;

/**
 * Turns SQL text into a value-free form safe to put in a batch (spec 02 §4, ADR-0004).
 *
 * A single pass over the bytes: literals and Yii parameters `:qpN` become `?`, comments and runs
 * of whitespace become one space, a list of values after `IN` becomes its first element and `...`
 * (ADR-0011), everything else is copied. Anything the scanner is not sure about gives `null`.
 */
final class SqlNormalizer
{
    public const DEFAULT_MAX_QUERY_LENGTH = 8192;
    public const ELLIPSIS = QueryText::ELLIPSIS;

    private const WHITESPACE = " \t\n\r\f\v";
    private const WORD_START = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_';
    private const WORD_REST = self::WORD_START . '0123456789$';

    /**
     * @throws \InvalidArgumentException when `$maxQueryLength` cannot hold {@see self::ELLIPSIS}
     */
    public function __construct(
        private readonly int $maxQueryLength = self::DEFAULT_MAX_QUERY_LENGTH,
    ) {
        QueryText::assertMaxLength($maxQueryLength);
    }

    /**
     * Replaces literals with `?`, turns comments and whitespace runs into one space, then truncates
     * to `maxQueryLength` bytes including {@see self::ELLIPSIS}, on a character boundary.
     *
     * @param string $db `mysql` or `pgsql`; any other value returns null
     *
     * @return string|null null when the text cannot be normalised with certainty
     */
    public function normalize(string $sql, string $db): ?string
    {
        $dialect = Dialect::tryFrom($db);
        if ($dialect === null || preg_match('//u', $sql) !== 1) {
            return null;
        }
        $normalized = $this->scan($sql, $dialect);

        return $normalized === null ? null : QueryText::truncate($normalized, $this->maxQueryLength);
    }

    /**
     * First word of the command in lower case (spec 02 §2, field `op`), after whitespace, opening
     * parentheses and comments. Comments follow the dialect of `$db` (spec 02 §4): `#` and `--`
     * followed by whitespace for `mysql`, `--` for `pgsql`; for any other `$db` only `--` and `/* *\/`. A word is a run of ASCII letters and `_`.
     * Empty string when anything else comes first or a comment is not closed.
     */
    public function operation(string $sql, string $db): string
    {
        $dialect = Dialect::tryFrom($db);
        $length = strlen($sql);
        $i = 0;
        while ($i < $length) {
            $char = $sql[$i];
            if ($char === '(' || str_contains(self::WHITESPACE, $char)) {
                $i++;
            } elseif (($end = $this->commentEnd($sql, $i, $dialect)) !== null) {
                if ($end < 0) {
                    return '';
                }
                $i = $end;
            } else {
                break;
            }
        }
        $word = strspn($sql, self::WORD_START, $i);

        return $word > 0 ? strtolower(substr($sql, $i, $word)) : '';
    }

    private function scan(string $sql, Dialect $dialect): ?string
    {
        $length = strlen($sql);
        $out = '';
        $space = false;
        // Last token copied to `$out` when it was a bare word, to recognise `IN (`.
        $lastWord = null;
        // Per open parenthesis: for an `IN` list the text before it, which `$out` goes back to at `)`;
        // null for any other parenthesis. The list gets its own buffer so collapsing it copies only the list.
        $parens = [];
        $i = 0;
        while ($i < $length) {
            $char = $sql[$i];

            if (str_contains(self::WHITESPACE, $char)) {
                $space = true;
                $i++;
                continue;
            }
            $commentEnd = $this->commentEnd($sql, $i, $dialect);
            if ($commentEnd !== null) {
                if ($commentEnd < 0) {
                    return null;
                }
                $space = true;
                $i = $commentEnd;
                continue;
            }

            if ($space && $out !== '') {
                $out .= ' ';
            }
            $space = false;
            $previousWord = $lastWord;
            $lastWord = null;

            if ($char === "'") {
                $end = $this->quotedEnd($sql, $i, "'", $dialect->backslashEscapes());
                if ($end < 0) {
                    return null;
                }
                $out .= '?';
                $i = $end;
            } elseif ($char === '"') {
                $end = $this->quotedEnd($sql, $i, '"', $dialect->backslashEscapes());
                if ($end < 0) {
                    return null;
                }
                $out .= $dialect->doubleQuoteIsString() ? '?' : substr($sql, $i, $end - $i);
                $i = $end;
            } elseif ($char === '`') {
                if (!$dialect->backtickIdentifiers()) {
                    return null;
                }
                $end = $this->quotedEnd($sql, $i, '`', false);
                if ($end < 0) {
                    return null;
                }
                $out .= substr($sql, $i, $end - $i);
                $i = $end;
            } elseif ($char === '$' && $dialect->dollarSyntax()) {
                $end = $this->dollarEnd($sql, $i);
                if ($end === null) {
                    return null;
                }
                [$end, $isLiteral] = $end;
                $out .= $isLiteral ? '?' : substr($sql, $i, $end - $i);
                $i = $end;
            } elseif (ctype_digit($char) || ($char === '.' && $i + 1 < $length && ctype_digit($sql[$i + 1]) && !$this->endsWithWord($out))) {
                $end = $this->numberEnd($sql, $i);
                $run = $end + strspn($sql, self::WORD_REST, $end);
                if ($run > $end || ($end < $length && ord($sql[$end]) >= 0x80)) {
                    // `123abc` is a MySQL identifier or a typo; either way the digits may be a value.
                    return null;
                }
                $out .= '?';
                $i = $end;
            } elseif (str_contains(self::WORD_START, $char) || ord($char) >= 0x80) {
                $end = $i + 1 + strspn($sql, self::WORD_REST, $i + 1);
                while ($end < $length && ord($sql[$end]) >= 0x80) {
                    $end += 1 + strspn($sql, self::WORD_REST, $end + 1);
                }
                $word = substr($sql, $i, $end - $i);
                if ($end < $length && $sql[$end] === "'" && $this->isLiteralPrefix($word, $dialect)) {
                    $backslash = $dialect->backslashEscapes() || strcasecmp($word, 'e') === 0;
                    $literalEnd = $this->quotedEnd($sql, $end, "'", $backslash);
                    if ($literalEnd < 0) {
                        return null;
                    }
                    $out .= '?';
                    $i = $literalEnd;
                } else {
                    $out .= $word;
                    $lastWord = $word;
                    $i = $end;
                }
            } elseif ($char === ':' && ($sql[$i + 1] ?? '') === ':') {
                // A PostgreSQL cast; the type name after it is a word, not a parameter, as PDO reads it.
                $out .= '::';
                $i += 2;
            } elseif ($char === ':' && $i + 1 < $length && str_contains(self::WORD_START, $sql[$i + 1])) {
                $end = $i + 1 + strspn($sql, self::WORD_REST, $i + 1);
                $name = substr($sql, $i, $end - $i);
                // Yii numbers its parameters by position, so the number says nothing about the structure.
                $yiiParameter = strncmp($name, ':qp', 3) === 0 && strlen($name) > 3
                    && strspn($name, '0123456789', 3) === strlen($name) - 3;
                $out .= $yiiParameter ? '?' : $name;
                $i = $end;
            } elseif ($char === '(') {
                if ($previousWord !== null && strcasecmp($previousWord, 'in') === 0) {
                    $parens[] = $out;
                    $out = '(';
                } else {
                    $parens[] = null;
                    $out .= '(';
                }
                $i++;
            } elseif ($char === ')' && $parens !== []) {
                if ($parens[array_key_last($parens)] === null) {
                    array_pop($parens);
                } else {
                    $list = $this->valueList($out, 1, true);
                    $inner = $list !== null && $list[0] === strlen($out) && $list[2] >= 2 ? '(' . $list[1] . ', ...' : $out;
                    // Popped straight into `$out`, so the append below extends it in place instead of copying it.
                    $out = array_pop($parens);
                    $out .= $inner;
                }
                $out .= ')';
                $i++;
            } else {
                $out .= $char;
                $i++;
            }
        }
        // An `IN` list that is never closed stays as it is.
        while ($parens !== []) {
            $before = array_pop($parens);
            if ($before !== null) {
                $out = $before . $out;
            }
        }

        return $out;
    }

    /**
     * Position after the comment starting at `$i`, -1 for a comment that is not closed or is a
     * nested PostgreSQL block comment, null when no comment starts here. A null dialect uses only the common comment syntax.
     */
    private function commentEnd(string $sql, int $i, ?Dialect $dialect): ?int
    {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';
        if ($char === '/' && $next === '*') {
            $close = strpos($sql, '*/', $i + 2);
            if ($close === false) {
                return -1;
            }
            // PostgreSQL nests block comments; the first `*/` would not end this one.
            $nested = $dialect === Dialect::PgSql && str_contains(substr($sql, $i + 2, $close - $i - 2), '/*');

            return $nested ? -1 : $close + 2;
        }
        $lineComment = ($char === '#' && $dialect?->hashComments() === true)
            || ($char === '-' && $next === '-' && !($dialect?->dashCommentNeedsSpace() === true
                && isset($sql[$i + 2]) && !str_contains(self::WHITESPACE, $sql[$i + 2])));
        if (!$lineComment) {
            return null;
        }
        $newline = strpos($sql, "\n", $i);

        return $newline === false ? strlen($sql) : $newline + 1;
    }

    /**
     * Position after the quoted text opened at `$i` by `$quote`, or -1 when it is not closed.
     * A doubled quote is an escaped quote; with `$backslash` a backslash escapes the next byte.
     */
    private function quotedEnd(string $sql, int $i, string $quote, bool $backslash): int
    {
        $length = strlen($sql);
        $j = $i + 1;
        while ($j < $length) {
            $char = $sql[$j];
            if ($backslash && $char === '\\') {
                $j += 2;
            } elseif ($char !== $quote) {
                $j++;
            } elseif (($sql[$j + 1] ?? '') === $quote) {
                $j += 2;
            } else {
                return $j + 1;
            }
        }

        return -1;
    }

    /**
     * PostgreSQL `$`: a parameter `$1` (copied), a `$$` or `$tag$` literal (replaced), or null
     * for anything else, including a literal that is not closed.
     *
     * @return array{int, bool}|null end position and whether it is a literal
     */
    private function dollarEnd(string $sql, int $i): ?array
    {
        $digits = strspn($sql, '0123456789', $i + 1);
        if ($digits > 0) {
            return [$i + 1 + $digits, false];
        }
        $tag = 0;
        if (str_contains(self::WORD_START, $sql[$i + 1] ?? ' ')) {
            $tag = strspn($sql, self::WORD_START . '0123456789', $i + 1);
        }
        if (($sql[$i + 1 + $tag] ?? '') !== '$') {
            return null;
        }
        $delimiter = substr($sql, $i, $tag + 2);
        $close = strpos($sql, $delimiter, $i + strlen($delimiter));

        return $close === false ? null : [$close + strlen($delimiter), true];
    }

    /**
     * Reads a comma-separated list of values from normalised text, starting at `$i`; with `$tuples`
     * an item may also be a parenthesised list of values. Linear, so a list of any length is safe.
     *
     * @return array{int, string, int}|null position after the list, its first item and the number of
     *                                      items; null when an item is not a value
     */
    private function valueList(string $text, int $i, bool $tuples): ?array
    {
        $first = '';
        $count = 0;
        while (true) {
            $i += strspn($text, ' ', $i);
            $start = $i;
            if ($tuples && ($text[$i] ?? '') === '(') {
                $tuple = $this->valueList($text, $i + 1, false);
                if ($tuple === null || ($text[$tuple[0]] ?? '') !== ')') {
                    return null;
                }
                $i = $tuple[0] + 1;
            } else {
                $end = $this->valueEnd($text, $i);
                if ($end === null) {
                    return null;
                }
                $i = $end;
            }
            if ($count++ === 0) {
                $first = substr($text, $start, $i - $start);
            }
            $i += strspn($text, ' ', $i);
            if (($text[$i] ?? '') !== ',') {
                return [$i, $first, $count];
            }
            $i++;
        }
    }

    /**
     * Position after a value in normalised text starting at `$i`, null when none starts there: `?`,
     * `:name`, `$1`, `NULL`, `TRUE`, `FALSE`, `DATE ?`, `TIME ?`, `TIMESTAMP ?`, each also after `+` or `-`.
     */
    private function valueEnd(string $text, int $i): ?int
    {
        $char = $text[$i] ?? '';
        if ($char === '+' || $char === '-') {
            $i++;
            $i += strspn($text, ' ', $i);
            $char = $text[$i] ?? '';
        }
        if ($char === '?') {
            return $i + 1;
        }
        $word = strspn($text, self::WORD_REST, $i);
        if ($word > 0 && str_contains(self::WORD_START, $char)) {
            $upper = strtoupper(substr($text, $i, $word));
            if (in_array($upper, ['NULL', 'TRUE', 'FALSE'], true)) {
                return $i + $word;
            }
            if (in_array($upper, ['DATE', 'TIME', 'TIMESTAMP'], true) && substr($text, $i + $word, 2) === ' ?') {
                return $i + $word + 2;
            }

            return null;
        }
        if ($char === ':' && str_contains(self::WORD_START, $text[$i + 1] ?? ' ')) {
            return $i + 2 + strspn($text, self::WORD_REST, $i + 2);
        }
        if ($char === '$') {
            $digits = strspn($text, '0123456789', $i + 1);

            return $digits > 0 ? $i + 1 + $digits : null;
        }

        return null;
    }

    /** Position after a numeric literal starting at `$i`: hex, binary, or decimal with fraction and exponent. */
    private function numberEnd(string $sql, int $i): int
    {
        if ($sql[$i] === '0' && isset($sql[$i + 2]) && ($sql[$i + 1] === 'x' || $sql[$i + 1] === 'X')
            && ctype_xdigit($sql[$i + 2])) {
            return $i + 2 + strspn($sql, '0123456789abcdefABCDEF', $i + 2);
        }
        if ($sql[$i] === '0' && isset($sql[$i + 2]) && ($sql[$i + 1] === 'b' || $sql[$i + 1] === 'B')
            && strspn($sql, '01', $i + 2) > 0) {
            return $i + 2 + strspn($sql, '01', $i + 2);
        }
        $j = $i + strspn($sql, '0123456789', $i);
        if (($sql[$j] ?? '') === '.') {
            $j += 1 + strspn($sql, '0123456789', $j + 1);
        }
        if (($sql[$j] ?? '') === 'e' || ($sql[$j] ?? '') === 'E') {
            $sign = ($sql[$j + 1] ?? '') === '+' || ($sql[$j + 1] ?? '') === '-' ? 1 : 0;
            $exponent = strspn($sql, '0123456789', $j + 1 + $sign);
            if ($exponent > 0) {
                $j += 1 + $sign + $exponent;
            }
        }

        return $j;
    }

    /** Word directly before a quote that makes it one literal: `X'..'`, `B'..'`, `N'..'`, `E'..'` (pgsql), `_charset'..'` (mysql). */
    private function isLiteralPrefix(string $word, Dialect $dialect): bool
    {
        $lower = strtolower($word);
        if (in_array($lower, ['x', 'b', 'n'], true)) {
            return true;
        }

        return $dialect === Dialect::PgSql ? $lower === 'e' : $word[0] === '_';
    }

    private function endsWithWord(string $out): bool
    {
        if ($out === '') {
            return false;
        }
        $last = $out[strlen($out) - 1];

        return str_contains(self::WORD_REST, $last) || $last === '`' || $last === '"' || ord($last) >= 0x80;
    }
}
