<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\mongodb;

use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\support\CallerFrames;
use mrstroz\querymonitoring\support\Guard;

/**
 * Turns the start and end events of one driver command into an entry (spec 01 §3).
 *
 * One recorder per client key of the driver, so `requestId` counts in one client. Between the two events it
 * keeps only `op` and `query` of the command, never the document. Every method runs inside the guard, so a
 * failure here never reaches the driver or the application.
 */
final class Recorder
{
    /** Frames searched for `caller`, counted from the guard's closure in the end-event methods (spec 01 §3). */
    public const TRACE_LIMIT = 64;

    /** @var array<string, array{string, ?string}> `op` and `query` by `requestId` */
    private array $pending = [];

    public function __construct(
        private readonly string $connectionId,
        private readonly MongoDbNormalizer $normalizer,
        private readonly QueryCollector $collector,
        private readonly Guard $guard,
        private readonly CallerFrames $callers,
    ) {}

    /**
     * @param \Closure(): object $command the command document, read only when the collector takes the entry
     */
    public function started(string $requestId, string $commandName, \Closure $command): void
    {
        $this->guard->run(function () use ($requestId, $commandName, $command): void {
            // After finalisation and while the adapter sends there is no state; once the batch is full the
            // command only counts in `dropped`, without reading or normalising the document.
            if (!$this->collector->isAccepting() || $this->collector->dropIfFull()) {
                return;
            }
            $this->pending[$requestId] = [$commandName, $this->normalizer->normalize($commandName, $command())];
        }, 'mongodb started');
    }

    public function succeeded(string $requestId, int $durationMicros): void
    {
        $this->guard->run(function () use ($requestId, $durationMicros): void {
            $state = $this->take($requestId);
            if ($state === null || !$this->collector->isAccepting() || $this->collector->dropIfFull()) {
                return;
            }
            // Called right here, in the closure: frame 0 of the limit is this closure (spec 01 §3).
            $caller = $this->callers->frames(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::TRACE_LIMIT));
            $this->collector->add(QueryEntry::success('mongodb', $this->connectionId, $state[0], $state[1], self::timeMs($durationMicros), $caller));
        }, 'mongodb succeeded');
    }

    /**
     * @param string $code numeric code of the server error, as text
     */
    public function failed(string $requestId, int $durationMicros, string $code): void
    {
        $this->guard->run(function () use ($requestId, $durationMicros, $code): void {
            $state = $this->take($requestId);
            if ($state === null || !$this->collector->isAccepting() || $this->collector->dropIfFull()) {
                return;
            }
            // Called right here, in the closure: frame 0 of the limit is this closure (spec 01 §3).
            $caller = $this->callers->frames(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::TRACE_LIMIT));
            $this->collector->add(QueryEntry::error('mongodb', $this->connectionId, $state[0], $state[1], self::timeMs($durationMicros), $code, $caller));
        }, 'mongodb failed');
    }

    /**
     * Removes the state of `$requestId` first, so a failure later in the end event leaves nothing behind.
     *
     * @return array{string, ?string}|null
     */
    private function take(string $requestId): ?array
    {
        $state = $this->pending[$requestId] ?? null;
        unset($this->pending[$requestId]);

        return $state;
    }

    private static function timeMs(int $durationMicros): float
    {
        return round($durationMicros / 1000, 3);
    }
}
