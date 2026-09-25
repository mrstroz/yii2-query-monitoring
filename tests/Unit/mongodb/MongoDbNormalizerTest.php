<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\mongodb;

use MongoDB\BSON\Document;
use mrstroz\querymonitoring\mongodb\MongoDbNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * YQM-34: spec 02 §4 (normalizacja MongoDB). YQM-42: `$in` i `$nin` (ADR-0011). YQM-43: any depth (ADR-0004). YQM-44: `distinct`. YQM-45: truncation. Commands are canonical Extended JSON turned into the tree
 * `CommandStartedEvent::getCommand()` gives, so BSON types stay objects; ext-mongodb is needed, no server.
 */
final class MongoDbNormalizerTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../fixtures/mongodb-commands.json';

    /**
     * Commands yii2-mongodb sent in the probe of YQM-32.
     *
     * @return iterable<string, array{string, ?string}>
     */
    public static function provideProbedCommandCases(): iterable
    {
        yield 'delete-all' => ['delete-all', 'qm_probe_docs filter{}'];
        yield 'insert' => ['insert', 'qm_probe_docs n:3'];
        yield 'find' => ['find', 'qm_probe_docs filter{k:{$in:[?,...]},tags:?} sort{k:?} limit:? skip:?'];
        yield 'update' => ['update', 'qm_probe_docs filter{k:?} update{$set:{v:?}}'];
        yield 'delete' => ['delete', 'qm_probe_docs filter{k:?}'];
        yield 'aggregate' => ['aggregate', 'qm_probe_docs pipeline[{$match:{k:{$gt:?}}},{$group:{_id:?,n:{$sum:?}}}]'];
        yield 'count' => ['count', 'qm_probe_docs filter{k:{$gte:?}}'];
        yield 'find of batch()' => ['find-batch', 'qm_probe_docs filter{}'];
        yield 'getMore' => ['getMore', 'qm_probe_docs'];
        yield 'last getMore' => ['getMore-last', 'qm_probe_docs'];
        yield 'findAndModify' => ['findAndModify', 'qm_probe_docs filter{k:?} update{$set:{v:?}}'];
        yield 'find of an unfinished cursor' => ['find-unfinished', 'qm_probe_docs filter{}'];
        yield 'killCursors is not described' => ['killCursors', null];
        yield 'find by ObjectId' => ['find-objectid', 'qm_contact filter{_id:?}'];
        yield 'insert with duplicate key' => ['insert-writeErrors', 'qm_probe_errors n:1'];
        yield 'rejected filter' => ['find-failed', 'qm_probe_errors filter{$qmInvalid:?}'];
    }

    #[DataProvider('provideProbedCommandCases')]
    public function testProbedCommand(string $case, ?string $expected): void
    {
        // Decoded as objects, so an empty document stays `{}` when encoded back.
        $fixture = json_decode((string) file_get_contents(self::FIXTURE), false, 512, JSON_THROW_ON_ERROR);
        $command = self::command((string) json_encode($fixture->{$case}->command));

        self::assertSame($expected, self::normalizer()->normalize((string) array_key_first((array) $command), $command));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function provideRuleCases(): iterable
    {
        yield 'sections in the order of the text form' => ['find', '{"find":"c","skip":1,"limit":2,"sort":{"a":1},"filter":{"b":"x"}}', 'c filter{b:?} sort{a:?} limit:? skip:?'];
        yield 'keys in the order of the command' => ['find', '{"find":"c","filter":{"z":1,"a":2}}', 'c filter{z:?,a:?}'];
        yield 'no filter means no section' => ['find', '{"find":"c"}', 'c'];
        yield 'fields outside the sections stay out' => ['find', '{"find":"c","filter":{},"projection":{"secret":1},"batchSize":5,"$db":"qm","lsid":{"id":{"$binary":{"base64":"oEcz6VP/RtqxNM49ySHT7Q==","subType":"04"}}},"$clusterTime":{"clusterTime":{"$timestamp":{"t":1,"i":1}}},"txnNumber":{"$numberLong":"7"},"$readPreference":{"mode":"primary"}}', 'c filter{}'];
        yield 'boolean and null are values' => ['find', '{"find":"c","filter":{"a":true,"b":null}}', 'c filter{a:?,b:?}'];
        yield 'BSON values are one value' => ['find', '{"find":"c","filter":{"d":{"$date":{"$numberLong":"1"}},"r":{"$regularExpression":{"pattern":"^a","options":"i"}},"b":{"$binary":{"base64":"AA==","subType":"00"}},"m":{"$numberDecimal":"1.5"},"l":{"$numberLong":"9"}}}', 'c filter{d:?,r:?,b:?,m:?,l:?}'];
        yield '$in of three values' => ['find', '{"find":"c","filter":{"a":{"$in":[1,2,3]}}}', 'c filter{a:{$in:[?,...]}}'];
        yield '$in of two values' => ['find', '{"find":"c","filter":{"a":{"$in":["x","y"]}}}', 'c filter{a:{$in:[?,...]}}'];
        yield '$in of one value stays' => ['find', '{"find":"c","filter":{"a":{"$in":[1]}}}', 'c filter{a:{$in:[?]}}'];
        yield 'empty $in stays' => ['find', '{"find":"c","filter":{"a":{"$in":[]}}}', 'c filter{a:{$in:[]}}'];
        yield '$nin of values' => ['find', '{"find":"c","filter":{"a":{"$nin":[1,2,3]}}}', 'c filter{a:{$nin:[?,...]}}'];
        yield '$in of BSON values' => ['find', '{"find":"c","filter":{"_id":{"$in":[{"$oid":"650000000000000000000001"},{"$oid":"650000000000000000000002"}]}}}', 'c filter{_id:{$in:[?,...]}}'];
        yield '$in with a document stays' => ['find', '{"find":"c","filter":{"a":{"$in":[1,{"b":2}]}}}', 'c filter{a:{$in:[?,{b:?}]}}'];
        yield '$in with an array stays' => ['find', '{"find":"c","filter":{"a":{"$in":[[1,2],[3]]}}}', 'c filter{a:{$in:[[?,?],[?]]}}'];
        yield '$all is not collapsed' => ['find', '{"find":"c","filter":{"a":{"$all":[1,2,3]}}}', 'c filter{a:{$all:[?,?,?]}}'];
        yield '$in with a field path stays' => ['find', '{"find":"c","filter":{"a":{"$in":["$x",1]}}}', 'c filter{a:{$in:[?,?]}}'];
        yield '$in expression of two fields stays' => ['aggregate', '{"aggregate":"c","pipeline":[{"$project":{"x":{"$in":["$a","$b"]}}}],"cursor":{}}', 'c pipeline[{$project:{x:{$in:[?,?]}}}]'];
        yield '$in expression with three values' => ['find', '{"find":"c","filter":{"$expr":{"$in":["$status",[1,2,3]]}}}', 'c filter{$expr:{$in:[?,[?,...]]}}'];
        yield '$in expression with two values' => ['find', '{"find":"c","filter":{"$expr":{"$in":["$status",["a","b"]]}}}', 'c filter{$expr:{$in:[?,[?,...]]}}'];
        yield '$in expression with one value' => ['find', '{"find":"c","filter":{"$expr":{"$in":["$status",[1]]}}}', 'c filter{$expr:{$in:[?,[?]]}}'];
        yield '$in expression with fields in the array' => ['find', '{"find":"c","filter":{"$expr":{"$in":["$status",["$a","$b"]]}}}', 'c filter{$expr:{$in:[?,[?,?]]}}'];
        yield '$in in a pipeline' => ['aggregate', '{"aggregate":"c","pipeline":[{"$match":{"a":{"$in":[1,2,3]}}}],"cursor":{}}', 'c pipeline[{$match:{a:{$in:[?,...]}}}]'];
        yield 'arrays are no level' => ['find', '{"find":"c","filter":{"$or":[{"a":{"$gt":1}},{"b":2}]}}', 'c filter{$or:[{a:{$gt:?}},{b:?}]}'];
        yield 'three andWhere() of Yii' => ['find', '{"find":"c","filter":{"$and":[{"$and":[{"tenantId":"x"},{"status":{"$in":[1,2]}}]},{"k":3}]}}', 'c filter{$and:[{$and:[{tenantId:?},{status:{$in:[?,...]}}]},{k:?}]}'];
        yield 'eight levels of keys' => ['find', '{"find":"c","filter":{"a":{"b":{"c":{"d":{"e":{"f":{"g":{"h":1}}}}}}}}}', 'c filter{a:{b:{c:{d:{e:{f:{g:{h:?}}}}}}}}'];
        yield 'deep empty document' => ['find', '{"find":"c","filter":{"a":{"b":{"c":{"d":{"e":{}}}}}}}', 'c filter{a:{b:{c:{d:{e:{}}}}}}'];
        yield 'deep key in a pipeline' => ['aggregate', '{"aggregate":"c","pipeline":[{"$match":{"a":{"b":{"c":{"d":{"e":{"f":1}}}}}}}],"cursor":{}}', 'c pipeline[{$match:{a:{b:{c:{d:{e:{f:?}}}}}}}]'];
        yield 'Atlas Search compound in compound' => ['aggregate', '{"aggregate":"property","pipeline":[{"$search":{"index":"property","compound":{"filter":[{"compound":{"should":[{"equals":{"path":"status","value":1}},{"range":{"path":"price","gte":100}}]}}]}}},{"$limit":10}],"cursor":{}}', 'property pipeline[{$search:{index:?,compound:{filter:[{compound:{should:[{equals:{path:?,value:?}},{range:{path:?,gte:?}}]}}]}}},{$limit:?}]'];
        yield 'Atlas Search facet with an embedded document' => ['aggregate', '{"aggregate":"property","pipeline":[{"$searchMeta":{"index":"property","facet":{"operator":{"compound":{"filter":[{"embeddedDocument":{"path":"rooms","operator":{"compound":{"must":[{"equals":{"path":"rooms.type","value":"bed"}}]}}}}]}},"facets":{"byStatus":{"type":"string","path":"status"}}}}}],"cursor":{}}', 'property pipeline[{$searchMeta:{index:?,facet:{operator:{compound:{filter:[{embeddedDocument:{path:?,operator:{compound:{must:[{equals:{path:?,value:?}}]}}}}]}},facets:{byStatus:{type:?,path:?}}}}}]'];
        yield 'aggregate on the database is unknown' => ['aggregate', '{"aggregate":1,"pipeline":[],"cursor":{}}', null];
        yield 'count with limit and skip' => ['count', '{"count":"c","query":{"a":1},"limit":1,"skip":2}', 'c filter{a:?} limit:? skip:?'];
        yield 'findAndModify with sort' => ['findAndModify', '{"findAndModify":"c","query":{"a":1},"sort":{"b":-1},"update":{"$inc":{"n":1}}}', 'c filter{a:?} update{$inc:{n:?}} sort{b:?}'];
        yield 'update with two statements is unknown' => ['update', '{"update":"c","updates":[{"q":{"a":1},"u":{"$set":{"b":1}}},{"q":{"a":2},"u":{"$set":{"b":2}}}]}', null];
        yield 'delete with two statements is unknown' => ['delete', '{"delete":"c","deletes":[{"q":{"a":1},"limit":0},{"q":{"a":2},"limit":0}]}', null];
        yield 'update replacing the document' => ['update', '{"update":"c","updates":[{"q":{"a":1},"u":{"name":"x"}}]}', 'c filter{a:?} update{name:?}'];
        yield 'empty insert' => ['insert', '{"insert":"c","documents":[]}', 'c n:0'];
        yield 'command outside the table' => ['createIndexes', '{"createIndexes":"c","indexes":[{"key":{"a":1},"name":"a_1"}]}', null];
        yield 'distinct with a filter' => ['distinct', '{"distinct":"c","key":"a","query":{"b":1}}', 'c key:a filter{b:?}'];
        yield 'distinct without a filter' => ['distinct', '{"distinct":"c","key":"a"}', 'c key:a'];
        yield 'distinct of a dotted key' => ['distinct', '{"distinct":"c","key":"address.city","query":{"b":{"$in":[1,2]}}}', 'c key:address.city filter{b:{$in:[?,...]}}'];
        yield 'distinct with an unsafe key is unknown' => ['distinct', '{"distinct":"c","key":"a b"}', null];
        yield 'distinct with a key that is not text is unknown' => ['distinct', '{"distinct":"c","key":1}', null];
        yield 'distinct without a key is unknown' => ['distinct', '{"distinct":"c","query":{"b":1}}', null];
        yield 'getMore without collection' => ['getMore', '{"getMore":{"$numberLong":"1"}}', null];
        yield 'dotted field names stay' => ['find', '{"find":"c","filter":{"address.city":"Kraków"}}', 'c filter{address.city:?}'];
        yield 'collection with a space is unknown' => ['find', '{"find":"a b","filter":{}}', null];
        yield 'collection with vertical tab is unknown' => ['find', '{"find":"c\\u000bd","filter":{}}', null];
    }

    #[DataProvider('provideRuleCases')]
    public function testRule(string $name, string $json, ?string $expected): void
    {
        self::assertSame($expected, self::normalizer()->normalize($name, self::command($json)));
    }

    public function testNoValueOfAnyDataPositionReachesTheQuery(): void
    {
        $commands = [
            'find' => '{"find":"c","filter":{"a":"qm-v1","b":{"$in":["qm-v2",42420001]},"o":{"$oid":"6ab408ba951fc129f40b9eb2"}},"sort":{"s":-1},"limit":42420002,"skip":42420003}',
            'update' => '{"update":"c","updates":[{"q":{"a":"qm-v3"},"u":{"$set":{"b":"qm-v4"}},"upsert":true}]}',
            'delete' => '{"delete":"c","deletes":[{"q":{"a":"qm-v5"},"limit":1}]}',
            'count' => '{"count":"c","query":{"a":"qm-v6"},"limit":42420004}',
            'findAndModify' => '{"findAndModify":"c","query":{"a":"qm-v7"},"update":{"$set":{"b":"qm-v8"}},"sort":{"x":42420005}}',
            'aggregate' => '{"aggregate":"c","pipeline":[{"$match":{"a":"qm-v9"}},{"$limit":42420006}],"cursor":{}}',
            'insert' => '{"insert":"c","documents":[{"a":"qm-v10","n":42420007}]}',
            'distinct' => '{"distinct":"c","key":"k","query":{"a":"qm-v11","n":42420008}}',
        ];
        foreach ($commands as $name => $json) {
            $query = (string) self::normalizer()->normalize($name, self::command($json));

            self::assertNotSame('', $query, $name);
            self::assertDoesNotMatchRegularExpression('/qm-v\d|4242000\d|6ab408ba/', $query, "{$name}: {$query}");
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnsafeFieldNameCases(): iterable
    {
        foreach ([':', '}', '{', '[', ']', ','] as $char) {
            yield "syntax {$char}" => ["a{$char}b"];
        }
        yield 'space' => ['a b'];
        yield 'tab' => ["a\tb"];
        yield 'new line' => ["a\nb"];
        yield 'vertical tab' => ["a\x0Bb"];
        yield 'form feed' => ["a\x0Cb"];
        yield 'no-break space' => ["a\u{00A0}b"];
        yield 'line separator' => ["a\u{2028}b"];
        yield 'NUL' => ["a\x00b"];
        yield 'empty' => [''];
        yield 'not UTF-8' => ["a\xff"];
    }

    #[DataProvider('provideUnsafeFieldNameCases')]
    public function testUnsafeFieldNameIsUnknown(string $name): void
    {
        // Built directly: BSON would reject a NUL or a name that is not UTF-8 before the normaliser sees it.
        $command = (object) ['find' => 'c', 'filter' => (object) [$name => 1]];

        self::assertNull(self::normalizer()->normalize('find', $command));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function provideTruncationCases(): iterable
    {
        yield 'at the limit' => ['{"find":"c","filter":{"abcdefgh":1}}', 20, 'c filter{abcdefgh:?}'];
        yield 'one byte over the limit' => ['{"find":"c","filter":{"abcdefgh":1}}', 19, 'c filter{abcdefg…'];
        // `ó` takes bytes 14 and 15, so a cut after byte 14 moves back before it.
        yield 'on a character boundary' => ['{"find":"c","filter":{"Kraków":1}}', 17, 'c filter{Krak…'];
        yield 'limit of the ellipsis alone' => ['{"find":"c","filter":{}}', 3, '…'];
    }

    #[DataProvider('provideTruncationCases')]
    public function testQueryOverTheLimitIsTruncated(string $json, int $maxQueryLength, string $expected): void
    {
        self::assertSame($expected, (new MongoDbNormalizer($maxQueryLength))->normalize('find', self::command($json)));
    }

    public function testLongGeoWithinPolygonIsTruncated(): void
    {
        $ring = (string) json_encode(array_fill(0, 2000, [21.01, 52.23]));
        $json = '{"aggregate":"property","pipeline":[{"$search":{"index":"property","geoWithin":{"path":"location","geometry":{"type":"Polygon","coordinates":[' . $ring . ']}}}}],"cursor":{}}';

        $query = (string) self::normalizer()->normalize('aggregate', self::command($json));

        self::assertSame(8192, strlen($query));
        self::assertStringStartsWith('property pipeline[{$search:{index:?,geoWithin:{path:?,geometry:{type:?,coordinates:[[[?,?],', $query);
        self::assertStringEndsWith('…', $query);
    }

    public function testLimitShorterThanTheEllipsisIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MongoDbNormalizer(2);
    }

    private static function normalizer(): MongoDbNormalizer
    {
        return new MongoDbNormalizer(8192);
    }

    private static function command(string $json): object
    {
        $command = Document::fromJSON($json)->toPHP();
        self::assertIsObject($command);

        return $command;
    }
}
