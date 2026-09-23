<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\sql;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\sql\Recorder;
use mrstroz\querymonitoring\sql\SqlNormalizer;
use mrstroz\querymonitoring\support\CallerFrames;
use mrstroz\querymonitoring\support\Guard;
use mrstroz\querymonitoring\tests\Unit\LoggedTestCase;
use mrstroz\querymonitoring\tests\Unit\sql\deep\Deep;

/**
 * YQM-28, spec 02 §2: the recorder takes the trace itself, limited to {@see Recorder::TRACE_LIMIT} frames,
 * so an application frame deeper than that gives `caller: []`.
 *
 * The stack at the trace, from spec 01 §2: `[0]` the guard's closure, `[1] Guard::run`, `[2] Recorder::record`
 * (called from {@see Deep}), then one frame per level of {@see Deep::record()}; the outermost level is called
 * from this file. With `L` levels this file's frame sits at index `2 + L`, inside the limit while `2 + L < TRACE_LIMIT`.
 */
final class RecorderCallerTest extends LoggedTestCase
{
    public function testApplicationFrameAtTheLastIndexInsideTheLimitIsFound(): void
    {
        $levels = Recorder::TRACE_LIMIT - 3;

        [$caller, $line] = $this->callerAt($levels);

        self::assertSame([$this->relative(__FILE__) . ':' . $line], $caller, 'application frame at index TRACE_LIMIT - 1');
    }

    public function testApplicationFrameJustBeyondTheLimitGivesEmptyCaller(): void
    {
        $levels = Recorder::TRACE_LIMIT - 2;

        self::assertSame([], $this->callerAt($levels)[0], 'no application frame within the limit');
    }

    public function testRecordedEntryCarriesCallerOfTheQuery(): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($collector, new CallerFrames($this->root(), $this->root() . '/vendor', $this->root() . '/src', null));

        $line = __LINE__ + 1;
        $recorder->record('SELECT 1', 1.0, null);
        $recorder->record('SELECT 2', 1.0, '42000');

        $queries = $collector->close(new \DateTimeImmutable())->queries;
        self::assertSame($this->relative(__FILE__) . ':' . $line, $queries[0]->caller[0], 'nearest application frame first');
        self::assertNotSame([], $queries[1]->caller, 'an error entry has caller too');
        self::assertSame('caller', array_key_last($queries[1]->toArray()), 'caller is the last field of the entry');
    }

    /**
     * Records one query under `$levels` frames of {@see Deep}, which plays vendor here.
     *
     * @return array{list<string>, int} the first element of `caller` (or none), and the line of the call
     */
    private function callerAt(int $levels): array
    {
        $collector = $this->collector();
        $frames = new CallerFrames($this->root(), __DIR__ . '/deep', $this->root() . '/src', null);
        $recorder = $this->recorder($collector, $frames);

        $line = __LINE__ + 1;
        Deep::record($levels, $recorder);

        $queries = $collector->close(new \DateTimeImmutable())->queries;
        self::assertCount(1, $queries);
        self::assertSame([], $this->errors());

        return [array_slice($queries[0]->caller, 0, 1), $line];
    }

    private function recorder(QueryCollector $collector, CallerFrames $frames): Recorder
    {
        return new Recorder('db', 'mysql', new SqlNormalizer(), $collector, new Guard(), $frames);
    }

    private function collector(): QueryCollector
    {
        return new QueryCollector('app', BatchType::Http, 'req_1', 'host');
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function relative(string $file): string
    {
        return substr($file, strlen($this->root()) + 1);
    }
}
