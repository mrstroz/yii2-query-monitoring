<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\mongodb;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\mongodb\MongoDbNormalizer;
use mrstroz\querymonitoring\mongodb\Recorder;
use mrstroz\querymonitoring\support\CallerFrames;
use mrstroz\querymonitoring\support\Guard;
use mrstroz\querymonitoring\tests\Unit\LoggedTestCase;
use mrstroz\querymonitoring\tests\Unit\mongodb\deep\Deep;

/**
 * YQM-38, spec 01 §3: the recorder takes the trace itself at the end event, limited to {@see Recorder::TRACE_LIMIT} frames.
 *
 * The stack at the trace: `[0]` the guard's closure, `[1] Guard::run`, `[2] Recorder::succeeded` (called from {@see Deep},
 * which plays the subscriber, frame 3 in the driver), then one frame per level of {@see Deep::succeeded()}; the
 * outermost level is called from this file. With `L` levels this file's frame sits at index `2 + L`.
 */
final class RecorderCallerTest extends LoggedTestCase
{
    public function testApplicationFrameAtTheLastIndexInsideTheLimitIsFound(): void
    {
        [$caller, $line] = $this->callerAt(Recorder::TRACE_LIMIT - 3);

        self::assertSame([$this->relative(__FILE__) . ':' . $line], $caller, 'application frame at index TRACE_LIMIT - 1');
    }

    public function testApplicationFrameJustBeyondTheLimitGivesEmptyCaller(): void
    {
        self::assertSame([], $this->callerAt(Recorder::TRACE_LIMIT - 2)[0], 'no application frame within the limit');
    }

    /**
     * @return array{list<string>, int} the first element of `caller` (or none), and the line of the call
     */
    private function callerAt(int $levels): array
    {
        $collector = new QueryCollector('app', BatchType::Http, 'req_1', 'host');
        $frames = new CallerFrames($this->root(), __DIR__ . '/deep', $this->root() . '/src', null);
        $recorder = new Recorder('mongodb', new MongoDbNormalizer(8192), $collector, new Guard(), $frames);
        $recorder->started('1', 'find', static fn(): object => (object) ['find' => 'c']);

        $line = __LINE__ + 1;
        Deep::succeeded($levels, $recorder);

        $queries = $collector->close(new \DateTimeImmutable())->queries;
        self::assertCount(1, $queries);
        self::assertSame([], $this->errors());

        return [array_slice($queries[0]->caller, 0, 1), $line];
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
