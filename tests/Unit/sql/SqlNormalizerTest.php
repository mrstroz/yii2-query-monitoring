<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\sql;

use mrstroz\querymonitoring\sql\SqlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * YQM-6: spec 02 §4 (normalizacja SQL) i 02 §2 (`op`).
 */
final class SqlNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function commonCases(): iterable
    {
        yield 'named parameter stays' => ['SELECT * FROM t WHERE a = :qp0', 'SELECT * FROM t WHERE a = :qp0'];
        yield 'many named parameters stay' => ['SELECT * FROM t WHERE a = :qp0 AND b = :qp10', 'SELECT * FROM t WHERE a = :qp0 AND b = :qp10'];
        yield 'positional parameter stays' => ['SELECT * FROM t WHERE a = ?', 'SELECT * FROM t WHERE a = ?'];
        yield 'string literal' => ["SELECT * FROM t WHERE a = 'alice@example.com'", 'SELECT * FROM t WHERE a = ?'];
        yield 'string literal next to parameter' => ["SELECT * FROM t WHERE a = :qp0 AND b = 'x'", 'SELECT * FROM t WHERE a = :qp0 AND b = ?'];
        yield 'doubled quote inside literal' => ["SELECT 'it''s'", 'SELECT ?'];
        yield 'empty literal' => ["SELECT ''", 'SELECT ?'];
        yield 'integer' => ['SELECT 1', 'SELECT ?'];
        yield 'decimal and exponent' => ['SELECT 1.5, 1e5', 'SELECT ?, ?'];
        yield 'minus stays as operator' => ['SELECT * FROM t WHERE a = -2', 'SELECT * FROM t WHERE a = -?'];
        yield 'limit and offset' => ['SELECT * FROM t LIMIT 10 OFFSET 20', 'SELECT * FROM t LIMIT ? OFFSET ?'];
        yield 'digits in identifiers stay' => ['SELECT col_2 FROM table1 WHERE t1.c2 = 3', 'SELECT col_2 FROM table1 WHERE t1.c2 = ?'];
        yield 'digits followed by letters are unknown' => ['SELECT * FROM 1table', null];
        yield 'number glued to word is unknown' => ['SELECT 123abc', null];
        yield 'in list is not merged' => ['SELECT * FROM t WHERE id IN (1, 2, 3)', 'SELECT * FROM t WHERE id IN (?, ?, ?)'];
        yield 'block comment becomes one space' => ['SELECT a/* c */FROM t', 'SELECT a FROM t'];
        yield 'block comment between spaces' => ['SELECT /* hint */ a FROM t', 'SELECT a FROM t'];
        yield 'line comment with space' => ["SELECT a -- tail\nFROM t", 'SELECT a FROM t'];
        yield 'line comment at the end' => ['SELECT a FROM t -- tail', 'SELECT a FROM t'];
        yield 'whitespace collapsed and trimmed' => ["  SELECT\ta \n\n FROM   t \r\n", 'SELECT a FROM t'];
        yield 'comment marker inside literal' => ["SELECT 'a -- b', 'c /* d */'", 'SELECT ?, ?'];
        yield 'whitespace inside literal is not a problem' => ["SELECT 'a    b'", 'SELECT ?'];
        yield 'unclosed literal' => ["SELECT * FROM t WHERE a = 'abc", null];
        yield 'unclosed block comment' => ['SELECT a /* never closed', null];
    }

    #[DataProvider('commonCases')]
    public function testCommonRulesForMysql(string $sql, ?string $expected): void
    {
        self::assertSame($expected, (new SqlNormalizer())->normalize($sql, 'mysql'));
    }

    #[DataProvider('commonCases')]
    public function testCommonRulesForPgsql(string $sql, ?string $expected): void
    {
        self::assertSame($expected, (new SqlNormalizer())->normalize($sql, 'pgsql'));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function mysqlCases(): iterable
    {
        yield 'double quotes are a literal' => ['SELECT "email" FROM "users"', 'SELECT ? FROM ?'];
        yield 'backticks stay' => ['SELECT `email` FROM `order` WHERE `id` = :qp0', 'SELECT `email` FROM `order` WHERE `id` = :qp0'];
        yield 'backslash escapes single quote' => ["SELECT 'a\\'b'", 'SELECT ?'];
        yield 'backslash escapes double quote' => ['SELECT "a\\"b"', 'SELECT ?'];
        yield 'escaped backslash closes literal' => ["SELECT 'a\\\\', 1", 'SELECT ?, ?'];
        yield 'backslash before quote at the end is unclosed' => ["SELECT 'a\\' , 1", null];
        yield 'hash comment' => ["SELECT a # tail\nFROM t", 'SELECT a FROM t'];
        yield 'double dash without space is two minuses' => ['SELECT 1--1', 'SELECT ?--?'];
        yield 'limit with comma' => ['SELECT * FROM t LIMIT 5, 10', 'SELECT * FROM t LIMIT ?, ?'];
        yield 'hex and bit literals' => ["SELECT 0x1F, X'1F', b'01'", 'SELECT ?, ?, ?'];
        yield 'unclosed double quote' => ['SELECT "abc', null];
        yield 'block comment ends at first close' => ['SELECT /* a /* b */ 1', 'SELECT ?'];
    }

    #[DataProvider('mysqlCases')]
    public function testMysqlDialect(string $sql, ?string $expected): void
    {
        self::assertSame($expected, (new SqlNormalizer())->normalize($sql, 'mysql'));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function pgsqlCases(): iterable
    {
        yield 'double quotes are identifiers' => ['SELECT "email" FROM "users"', 'SELECT "email" FROM "users"'];
        yield 'dollar parameters stay' => ['SELECT * FROM t WHERE a = $1 AND b = $12', 'SELECT * FROM t WHERE a = $1 AND b = $12'];
        yield 'cast stays' => ['SELECT a::int FROM t', 'SELECT a::int FROM t'];
        yield 'literal with cast' => ["SELECT '1'::int", 'SELECT ?::int'];
        yield 'backslash is an ordinary character' => ["SELECT 'a\\', 1", 'SELECT ?, ?'];
        yield 'escape string' => ["SELECT E'a\\'b'", 'SELECT ?'];
        yield 'dollar quoted' => ['SELECT $$secret$$', 'SELECT ?'];
        yield 'dollar quoted with tag' => ['SELECT $tag$se$$cret$tag$', 'SELECT ?'];
        yield 'hash is an operator' => ['SELECT 5 # 3', 'SELECT ? # ?'];
        yield 'double dash is always a comment' => ["SELECT a --tail\nFROM t", 'SELECT a FROM t'];
        yield 'bit and hex literals' => ["SELECT B'01', X'1F'", 'SELECT ?, ?'];
        yield 'unclosed escape string' => ["SELECT E'abc", null];
        yield 'unclosed dollar quote' => ['SELECT $$abc', null];
        yield 'unclosed tagged dollar quote' => ['SELECT $tag$abc$other$', null];
        yield 'other dollar is unknown' => ['SELECT $x FROM t', null];
        yield 'nested block comment is unknown' => ['SELECT /* a /* b */ secret */ 1', null];
    }

    #[DataProvider('pgsqlCases')]
    public function testPgsqlDialect(string $sql, ?string $expected): void
    {
        self::assertSame($expected, (new SqlNormalizer())->normalize($sql, 'pgsql'));
    }

    public function testSameSqlGivesDifferentResultPerDialect(): void
    {
        $normalizer = new SqlNormalizer();

        self::assertSame('SELECT ? FROM ?', $normalizer->normalize('SELECT "email" FROM "users"', 'mysql'));
        self::assertSame('SELECT "email" FROM "users"', $normalizer->normalize('SELECT "email" FROM "users"', 'pgsql'));
    }

    /**
     * @return iterable<int, array{string}>
     */
    public static function unsupportedDbs(): iterable
    {
        yield ['sqlite'];
        yield ['mongodb'];
        yield ['oci'];
        yield [''];
        yield ['MYSQL'];
    }

    #[DataProvider('unsupportedDbs')]
    public function testUnsupportedDbGivesNull(string $db): void
    {
        self::assertNull((new SqlNormalizer())->normalize('SELECT 1', $db));
    }

    public function testInvalidUtf8GivesNull(): void
    {
        $normalizer = new SqlNormalizer();

        self::assertNull($normalizer->normalize("SELECT * FROM t WHERE a = \xFF\xFE", 'mysql'));
        self::assertNull($normalizer->normalize("SELECT \xC5 FROM t", 'pgsql'));
    }

    public function testDefaultMaxQueryLength(): void
    {
        self::assertSame(2048, SqlNormalizer::DEFAULT_MAX_QUERY_LENGTH);
    }

    public function testShortQueryIsNotTruncated(): void
    {
        self::assertSame('SELECT a FROM t', (new SqlNormalizer(15))->normalize('SELECT a FROM t', 'mysql'));
    }

    public function testTruncationCountsEllipsisInBytes(): void
    {
        $result = (new SqlNormalizer(20))->normalize('SELECT a FROM table_with_long_name', 'mysql');

        self::assertSame('SELECT a FROM tab…', $result);
        self::assertSame(20, strlen($result));
    }

    public function testTruncationStopsAtCharacterBoundary(): void
    {
        // "SELECT a" is 8 bytes, each "ż" 2 bytes; 17 bytes are left before the 3-byte ellipsis.
        $result = (new SqlNormalizer(20))->normalize('SELECT ażżżżżżżżż FROM t', 'pgsql');

        self::assertSame('SELECT ażżżż…', $result);
        self::assertTrue(mb_check_encoding($result, 'UTF-8'));
        self::assertLessThanOrEqual(20, strlen($result));
    }

    public function testTruncationHappensAfterNormalisation(): void
    {
        $sql = "SELECT * FROM t WHERE a = '" . str_repeat('x', 5000) . "'";

        self::assertSame('SELECT * FROM t WHERE a = ?', (new SqlNormalizer())->normalize($sql, 'mysql'));
    }

    public function testWhitespaceIsCollapsedBeforeTruncation(): void
    {
        self::assertSame('SELECT a', (new SqlNormalizer(20))->normalize('SELECT' . str_repeat(' ', 3000) . 'a', 'mysql'));
    }

    public function testLongQueryIsCutToDefaultLimitWithValidUtf8(): void
    {
        $sql = 'SELECT zażółć FROM t WHERE id IN (' . implode(', ', range(1, 3000)) . ')';

        $result = (new SqlNormalizer())->normalize($sql, 'mysql');

        self::assertIsString($result);
        self::assertLessThanOrEqual(2048, strlen($result));
        self::assertGreaterThanOrEqual(2046, strlen($result));
        self::assertStringEndsWith('…', $result);
        self::assertStringStartsWith('SELECT zażółć FROM t WHERE id IN (?, ?, ?', $result);
        self::assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function operationCases(): iterable
    {
        yield 'select' => ['SELECT 1', 'mysql', 'select'];
        yield 'lower case and whitespace' => ["  \n\tselect 1", 'pgsql', 'select'];
        yield 'insert after block comment' => ['/* c */ INSERT INTO t VALUES (1)', 'mysql', 'insert'];
        yield 'update after line comment' => ["-- c\nUPDATE t SET a = 1", 'mysql', 'update'];
        yield 'opening parentheses' => ['((SELECT 1)) UNION (SELECT 2)', 'mysql', 'select'];
        yield 'with' => ['WITH x AS (SELECT 1) SELECT * FROM x', 'pgsql', 'with'];
        yield 'savepoint' => ['SAVEPOINT sp1', 'pgsql', 'savepoint'];
        yield 'hash comment in mysql' => ["# c\nSELECT 1", 'mysql', 'select'];
        yield 'hash is not a comment in pgsql' => ["# c\nSELECT 1", 'pgsql', ''];
        yield 'hash is not a comment for other db' => ["# c\nSELECT 1", 'sqlite', ''];
        yield 'common comments for other db' => ['/* x */ SELECT 1', 'sqlite', 'select'];
        yield 'double dash without space in mysql is not a comment' => ["--x\nSELECT 1", 'mysql', ''];
        yield 'double dash without space in pgsql is a comment' => ["--x\nSELECT 1", 'pgsql', 'select'];
        yield 'unclosed comment' => ['/* SELECT 1', 'mysql', ''];
        yield 'nested comment in pgsql' => ['/* a /* b */ c */ SELECT 1', 'pgsql', ''];
        yield 'nested-looking comment in mysql' => ['/* a /* b */ SELECT 1', 'mysql', 'select'];
        yield 'empty' => ['', 'mysql', ''];
        yield 'only whitespace' => ['   ', 'pgsql', ''];
        yield 'digit first' => ['1', 'mysql', ''];
    }

    #[DataProvider('operationCases')]
    public function testOperation(string $sql, string $db, string $expected): void
    {
        self::assertSame($expected, (new SqlNormalizer())->operation($sql, $db));
    }
}
