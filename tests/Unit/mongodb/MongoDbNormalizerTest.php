<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\mongodb;

use MongoDB\BSON\Document;
use mrstroz\querymonitoring\mongodb\MongoDbNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * YQM-34: spec 02 §4 (normalizacja MongoDB). Commands are canonical Extended JSON turned into the tree
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
        yield 'find' => ['find', 'qm_probe_docs filter{k:{$in:[?,?]},tags:?} sort{k:?} limit:? skip:?'];
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
        yield 'arrays are not merged' => ['find', '{"find":"c","filter":{"a":{"$in":[1,2,3]}}}', 'c filter{a:{$in:[?,?,?]}}'];
        yield 'arrays are no level' => ['find', '{"find":"c","filter":{"$or":[{"a":{"$gt":1}},{"b":2}]}}', 'c filter{$or:[{a:{$gt:?}},{b:?}]}'];
        yield 'three andWhere() of Yii' => ['find', '{"find":"c","filter":{"$and":[{"$and":[{"tenantId":"x"},{"status":{"$in":[1,2]}}]},{"k":3}]}}', 'c filter{$and:[{$and:[{tenantId:?},{status:{$in:[?,?]}}]},{k:?}]}'];
        yield 'five levels of keys' => ['find', '{"find":"c","filter":{"a":{"b":{"c":{"d":{"e":1}}}}}}', 'c filter{a:{b:{c:{d:{e:?}}}}}'];
        yield 'six levels of keys is unknown' => ['find', '{"find":"c","filter":{"a":{"b":{"c":{"d":{"e":{"f":1}}}}}}}', null];
        yield 'empty document at the sixth level' => ['find', '{"find":"c","filter":{"a":{"b":{"c":{"d":{"e":{}}}}}}}', 'c filter{a:{b:{c:{d:{e:{}}}}}}'];
        yield 'pipeline levels count inside each stage' => ['aggregate', '{"aggregate":"c","pipeline":[{"$match":{"a":{"b":{"c":{"d":1}}}}}],"cursor":{}}', 'c pipeline[{$match:{a:{b:{c:{d:?}}}}}]'];
        yield 'pipeline key at the sixth level is unknown' => ['aggregate', '{"aggregate":"c","pipeline":[{"$match":{"a":{"b":{"c":{"d":{"e":1}}}}}}],"cursor":{}}', null];
        yield 'aggregate on the database is unknown' => ['aggregate', '{"aggregate":1,"pipeline":[],"cursor":{}}', null];
        yield 'count with limit and skip' => ['count', '{"count":"c","query":{"a":1},"limit":1,"skip":2}', 'c filter{a:?} limit:? skip:?'];
        yield 'findAndModify with sort' => ['findAndModify', '{"findAndModify":"c","query":{"a":1},"sort":{"b":-1},"update":{"$inc":{"n":1}}}', 'c filter{a:?} update{$inc:{n:?}} sort{b:?}'];
        yield 'update with two statements is unknown' => ['update', '{"update":"c","updates":[{"q":{"a":1},"u":{"$set":{"b":1}}},{"q":{"a":2},"u":{"$set":{"b":2}}}]}', null];
        yield 'delete with two statements is unknown' => ['delete', '{"delete":"c","deletes":[{"q":{"a":1},"limit":0},{"q":{"a":2},"limit":0}]}', null];
        yield 'update replacing the document' => ['update', '{"update":"c","updates":[{"q":{"a":1},"u":{"name":"x"}}]}', 'c filter{a:?} update{name:?}'];
        yield 'empty insert' => ['insert', '{"insert":"c","documents":[]}', 'c n:0'];
        yield 'command outside the table' => ['createIndexes', '{"createIndexes":"c","indexes":[{"key":{"a":1},"name":"a_1"}]}', null];
        yield 'distinct is not described' => ['distinct', '{"distinct":"c","key":"a","query":{"b":1}}', null];
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

    public function testQueryOverTheLimitIsNullNotTruncated(): void
    {
        $command = self::command('{"find":"c","filter":{"abcdefgh":1}}');

        self::assertSame('c filter{abcdefgh:?}', (new MongoDbNormalizer(20))->normalize('find', $command));
        self::assertNull((new MongoDbNormalizer(19))->normalize('find', $command));
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
