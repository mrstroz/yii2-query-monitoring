<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\sql;

use mrstroz\querymonitoring\sql\SqlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * YQM-6: spec 02 §4 (normalizacja SQL) i 02 §2 (`op`). YQM-41: parametry `:qpN` i listy `IN` (ADR-0011).
 */
final class SqlNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function provideCommonCases(): iterable
    {
        yield 'named parameter stays' => ['SELECT * FROM t WHERE a = :id', 'SELECT * FROM t WHERE a = :id'];
        yield 'yii parameters become placeholders' => ['SELECT * FROM t WHERE a = :qp0 AND b = :qp10', 'SELECT * FROM t WHERE a = ? AND b = ?'];
        yield 'parameter like a yii one stays' => ['SELECT * FROM t WHERE a = :qpx AND b = :qp1a AND c = :qp', 'SELECT * FROM t WHERE a = :qpx AND b = :qp1a AND c = :qp'];
        yield 'positional parameter stays' => ['SELECT * FROM t WHERE a = ?', 'SELECT * FROM t WHERE a = ?'];
        yield 'string literal' => ["SELECT * FROM t WHERE a = 'alice@example.com'", 'SELECT * FROM t WHERE a = ?'];
        yield 'string literal next to parameter' => ["SELECT * FROM t WHERE a = :id AND b = 'x'", 'SELECT * FROM t WHERE a = :id AND b = ?'];
        yield 'doubled quote inside literal' => ["SELECT 'it''s'", 'SELECT ?'];
        yield 'empty literal' => ["SELECT ''", 'SELECT ?'];
        yield 'integer' => ['SELECT 1', 'SELECT ?'];
        yield 'decimal and exponent' => ['SELECT 1.5, 1e5', 'SELECT ?, ?'];
        yield 'minus stays as operator' => ['SELECT * FROM t WHERE a = -2', 'SELECT * FROM t WHERE a = -?'];
        yield 'limit and offset' => ['SELECT * FROM t LIMIT 10 OFFSET 20', 'SELECT * FROM t LIMIT ? OFFSET ?'];
        yield 'digits in identifiers stay' => ['SELECT col_2 FROM table1 WHERE t1.c2 = 3', 'SELECT col_2 FROM table1 WHERE t1.c2 = ?'];
        yield 'digits followed by letters are unknown' => ['SELECT * FROM 1table', null];
        yield 'number glued to word is unknown' => ['SELECT 123abc', null];
        yield 'number glued to non-ASCII letter is unknown' => ['SELECT 123ł', null];
        yield 'number and non-ASCII word apart' => ['SELECT 123 ł', 'SELECT ? ł'];
        yield 'in list of literals' => ['SELECT * FROM t WHERE id IN (1, 2, 3)', 'SELECT * FROM t WHERE id IN (?, ...)'];
        yield 'in list of two' => ['SELECT * FROM t WHERE id IN (1, 2)', 'SELECT * FROM t WHERE id IN (?, ...)'];
        yield 'in list of yii parameters' => ['SELECT * FROM t WHERE id IN (:qp0, :qp1, :qp2) AND b = :qp3', 'SELECT * FROM t WHERE id IN (?, ...) AND b = ?'];
        yield 'in list keeps the first named parameter' => ['SELECT * FROM t WHERE id IN (:a, :b)', 'SELECT * FROM t WHERE id IN (:a, ...)'];
        yield 'in list of negative numbers' => ['SELECT * FROM t WHERE id IN (-1, 2)', 'SELECT * FROM t WHERE id IN (-?, ...)'];
        yield 'in list of mixed values' => ["SELECT * FROM t WHERE id IN (?, 'a', :b, 1.5)", 'SELECT * FROM t WHERE id IN (?, ...)'];
        yield 'not in list' => ['SELECT * FROM t WHERE id NOT IN (1, 2, 3)', 'SELECT * FROM t WHERE id NOT IN (?, ...)'];
        yield 'lower case in without space' => ['select * from t where id in(1,2,3)', 'select * from t where id in(?, ...)'];
        yield 'in with whitespace and comment before list' => ["SELECT * FROM t WHERE id IN /* c */\n ( 1 ,\n2 )", 'SELECT * FROM t WHERE id IN (?, ...)'];
        yield 'in list of tuples' => ['SELECT * FROM t WHERE (a, b) IN ((:qp0, :qp1), (:qp2, :qp3))', 'SELECT * FROM t WHERE (a, b) IN ((?, ?), ...)'];
        yield 'in list of one element stays' => ['SELECT * FROM t WHERE id IN (:qp0)', 'SELECT * FROM t WHERE id IN (?)'];
        yield 'in list of one tuple stays' => ['SELECT * FROM t WHERE (a, b) IN ((1, 2))', 'SELECT * FROM t WHERE (a, b) IN ((?, ?))'];
        yield 'empty in list stays' => ['SELECT * FROM t WHERE id IN ()', 'SELECT * FROM t WHERE id IN ()'];
        yield 'in subquery stays' => ['SELECT * FROM t WHERE id IN (SELECT id FROM u WHERE a IN (1, 2))', 'SELECT * FROM t WHERE id IN (SELECT id FROM u WHERE a IN (?, ...))'];
        yield 'in list with expression stays' => ['SELECT * FROM t WHERE id IN (1, b + 1)', 'SELECT * FROM t WHERE id IN (?, b + ?)'];
        yield 'in list with function stays' => ['SELECT * FROM t WHERE id IN (1, ABS(2))', 'SELECT * FROM t WHERE id IN (?, ABS(?))'];
        yield 'in list with column stays' => ['SELECT * FROM t WHERE 1 IN (a, b)', 'SELECT * FROM t WHERE ? IN (a, b)'];
        yield 'nested tuple stays' => ['SELECT * FROM t WHERE (a, b) IN (((1, 2)), (3, 4))', 'SELECT * FROM t WHERE (a, b) IN (((?, ?)), (?, ?))'];
        yield 'list not after in stays' => ['INSERT INTO t (a, b) VALUES (1, 2), (3, 4)', 'INSERT INTO t (a, b) VALUES (?, ?), (?, ?)'];
        yield 'word ending with in is not in' => ['SELECT * FROM t WHERE COALESCE(a, b) = MIN(1, 2)', 'SELECT * FROM t WHERE COALESCE(a, b) = MIN(?, ?)'];
        yield 'in list with null and booleans' => ['SELECT * FROM t WHERE a IN (1, 2, NULL) AND b IN (TRUE, false)', 'SELECT * FROM t WHERE a IN (?, ...) AND b IN (TRUE, ...)'];
        yield 'in list of signed values' => ['SELECT * FROM t WHERE a IN (+1, - 2, -:b)', 'SELECT * FROM t WHERE a IN (+?, ...)'];
        yield 'in list of date literals' => ["SELECT * FROM t WHERE d IN (DATE '2020-01-01', date '2020-01-02', TIMESTAMP '2020-01-01 10:00')", 'SELECT * FROM t WHERE d IN (DATE ?, ...)'];
        yield 'in list with a column named like null stays' => ['SELECT * FROM t WHERE a IN (nullable, 1)', 'SELECT * FROM t WHERE a IN (nullable, ?)'];
        yield 'in list with date column stays' => ['SELECT * FROM t WHERE a IN (date, 1)', 'SELECT * FROM t WHERE a IN (date, ?)'];
        yield 'in list with subtraction stays' => ['SELECT * FROM t WHERE a IN (1 - 2, 3)', 'SELECT * FROM t WHERE a IN (? - ?, ?)'];
        yield 'in list inside a subquery in a list' => ['SELECT * FROM t WHERE a IN (1, (SELECT b FROM u WHERE c IN (1, 2)))', 'SELECT * FROM t WHERE a IN (?, (SELECT b FROM u WHERE c IN (?, ...)))'];
        yield 'unclosed in list stays' => ['SELECT * FROM t WHERE a IN (1, 2', 'SELECT * FROM t WHERE a IN (?, ?'];
        yield 'unclosed in list inside an unclosed parenthesis' => ['SELECT * FROM t WHERE (a IN (1, 2', 'SELECT * FROM t WHERE (a IN (?, ?'];
        yield 'unbalanced closing parenthesis' => ['SELECT a) FROM t WHERE id IN (1, 2)', 'SELECT a) FROM t WHERE id IN (?, ...)'];
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

    #[DataProvider('provideCommonCases')]
    public function testCommonRulesForMysql(string $sql, ?string $expected): void
    {
        self::assertSame($expected, (new SqlNormalizer())->normalize($sql, 'mysql'));
    }

    #[DataProvider('provideCommonCases')]
    public function testCommonRulesForPgsql(string $sql, ?string $expected): void
    {
        self::assertSame($expected, (new SqlNormalizer())->normalize($sql, 'pgsql'));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function provideMysqlCases(): iterable
    {
        yield 'double quotes are a literal' => ['SELECT "email" FROM "users"', 'SELECT ? FROM ?'];
        yield 'backticks stay' => ['SELECT `email` FROM `order` WHERE `id` = :qp0', 'SELECT `email` FROM `order` WHERE `id` = ?'];
        yield 'in list inside backticks stays' => ['SELECT `x IN (1, 2)` FROM t WHERE `in` IN (1, 2)', 'SELECT `x IN (1, 2)` FROM t WHERE `in` IN (?, ...)'];
        yield 'in list of double quoted strings' => ['SELECT * FROM t WHERE a IN ("x", "y")', 'SELECT * FROM t WHERE a IN (?, ...)'];
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

    #[DataProvider('provideMysqlCases')]
    public function testMysqlDialect(string $sql, ?string $expected): void
    {
        self::assertSame($expected, (new SqlNormalizer())->normalize($sql, 'mysql'));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function providePgsqlCases(): iterable
    {
        yield 'double quotes are identifiers' => ['SELECT "email" FROM "users"', 'SELECT "email" FROM "users"'];
        yield 'dollar parameters stay' => ['SELECT * FROM t WHERE a = $1 AND b = $12', 'SELECT * FROM t WHERE a = $1 AND b = $12'];
        yield 'cast stays' => ['SELECT a::int FROM t', 'SELECT a::int FROM t'];
        yield 'cast to a type named like a yii parameter stays' => ['SELECT a::qp0 FROM t', 'SELECT a::qp0 FROM t'];
        yield 'cast with spaces to a type named like a yii parameter stays' => ['SELECT a :: qp0 FROM t', 'SELECT a :: qp0 FROM t'];
        yield 'yii parameter before a cast' => ['SELECT arr[:qp0::qp1] FROM t', 'SELECT arr[?::qp1] FROM t'];
        yield 'in list of dollar parameters' => ['SELECT * FROM t WHERE a IN ($1, $2, $3)', 'SELECT * FROM t WHERE a IN ($1, ...)'];
        yield 'in list inside double quotes stays' => ['SELECT "x IN (1, 2)" FROM t WHERE a IN (1, 2)', 'SELECT "x IN (1, 2)" FROM t WHERE a IN (?, ...)'];
        yield 'in list with cast stays' => ['SELECT * FROM t WHERE a IN (1::int, 2)', 'SELECT * FROM t WHERE a IN (?::int, ?)'];
        yield 'in list of escape strings' => ["SELECT * FROM t WHERE a IN (E'x', \$\$y\$\$)", 'SELECT * FROM t WHERE a IN (?, ...)'];
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

    #[DataProvider('providePgsqlCases')]
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
     * @return iterable<string, array{string}>
     */
    public static function provideUnsupportedDbCases(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mongodb' => ['mongodb'];
        yield 'oci' => ['oci'];
        yield 'empty name' => [''];
        yield 'mysql in upper case' => ['MYSQL'];
    }

    #[DataProvider('provideUnsupportedDbCases')]
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
        self::assertSame(8192, SqlNormalizer::DEFAULT_MAX_QUERY_LENGTH);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideTooSmallLimitCases(): iterable
    {
        yield 'zero' => [0];
        yield 'shorter than the ellipsis' => [2];
        yield 'negative' => [-1];
    }

    #[DataProvider('provideTooSmallLimitCases')]
    public function testLimitSmallerThanEllipsisIsRejected(int $max): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SqlNormalizer($max);
    }

    public function testSmallestLimitGivesOnlyEllipsis(): void
    {
        $normalizer = new SqlNormalizer(3);

        self::assertSame('…', $normalizer->normalize('SELECT abcdef', 'mysql'));
        self::assertSame('a b', $normalizer->normalize('a  b', 'pgsql'));
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

    public function testInListIsCollapsedBeforeTruncation(): void
    {
        $sql = 'SELECT * FROM t WHERE id IN (' . implode(', ', range(1, 5000)) . ') AND a = :qp5000';

        self::assertSame('SELECT * FROM t WHERE id IN (?, ...) AND a = ?', (new SqlNormalizer())->normalize($sql, 'mysql'));
    }

    public function testSameStructureWithDifferentListLengthGivesSameQuery(): void
    {
        $normalizer = new SqlNormalizer();
        $query = static fn(int $count): string => 'SELECT * FROM `sap_prodat` WHERE `material_num` IN ('
            . implode(', ', array_map(static fn(int $i): string => ':qp' . $i, range(0, $count - 1)))
            . ') AND `plant` = :qp' . $count;

        $expected = 'SELECT * FROM `sap_prodat` WHERE `material_num` IN (?, ...) AND `plant` = ?';

        self::assertSame($expected, $normalizer->normalize($query(3), 'mysql'));
        self::assertSame($expected, $normalizer->normalize($query(211), 'mysql'));
    }

    public function testSameStructureWithDifferentListLengthGivesSameQueryInPgsql(): void
    {
        $normalizer = new SqlNormalizer();
        $query = static fn(int $count): string => 'SELECT * FROM "sap_prodat" WHERE "material_num" IN ('
            . implode(', ', array_map(static fn(int $i): string => ':qp' . $i, range(0, $count - 1)))
            . ') AND "plant" = :qp' . $count;
        $expected = 'SELECT * FROM "sap_prodat" WHERE "material_num" IN (?, ...) AND "plant" = ?';

        self::assertSame($expected, $normalizer->normalize($query(3), 'pgsql'));
        self::assertSame($expected, $normalizer->normalize($query(211), 'pgsql'));
    }

    public function testLongQueryIsCutToDefaultLimitWithValidUtf8(): void
    {
        // 5000 literals give about 20 KB after normalisation, well over the default limit.
        $sql = 'SELECT zażółć FROM t WHERE id = ' . implode(' + ', range(1, 5000));

        $result = (new SqlNormalizer())->normalize($sql, 'mysql');

        self::assertIsString($result);
        self::assertLessThanOrEqual(8192, strlen($result));
        self::assertGreaterThanOrEqual(8190, strlen($result));
        self::assertStringEndsWith('…', $result);
        self::assertStringStartsWith('SELECT zażółć FROM t WHERE id = ? + ? + ?', $result);
        self::assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    public function testQueryOfExactlyDefaultLimitIsKept(): void
    {
        $sql = 'SELECT ' . str_repeat('a', SqlNormalizer::DEFAULT_MAX_QUERY_LENGTH - 7);

        self::assertSame($sql, (new SqlNormalizer())->normalize($sql, 'mysql'), '8192 bytes fit');
    }

    public function testQueryOneByteOverDefaultLimitIsCut(): void
    {
        $sql = 'SELECT ' . str_repeat('a', SqlNormalizer::DEFAULT_MAX_QUERY_LENGTH - 6);

        $result = (new SqlNormalizer())->normalize($sql, 'mysql');

        self::assertIsString($result);
        self::assertSame(8192, strlen($result), '8193 bytes are cut to 8189 plus the 3-byte ellipsis');
        self::assertStringEndsWith('…', $result);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideOperationCases(): iterable
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

    #[DataProvider('provideOperationCases')]
    public function testOperation(string $sql, string $db, string $expected): void
    {
        self::assertSame($expected, (new SqlNormalizer())->operation($sql, $db));
    }
}
