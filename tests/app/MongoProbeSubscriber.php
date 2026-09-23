<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

use MongoDB\BSON\Document;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;

/**
 * YQM-32: a subscriber that only writes down what the driver hands it, for the probe of open question 2.
 *
 * Each event becomes one array: the subscriber's label, the event kind, the command name, `requestId`,
 * and on request the command document or the reply as canonical Extended JSON, or the stack positions of
 * the application frames. Positions count from a guard's closure as the package's end-event procedure
 * would take them: `[0] {closure}`, `[1] Guard::run`, `[2] Recorder::succeeded`, `[3] Subscriber::commandSucceeded`.
 * The probe finds its own `commandSucceeded`/`commandFailed` frame and shifts by {@see self::CAPTURE_OFFSET}.
 */
final class MongoProbeSubscriber implements CommandSubscriber
{
    public const CAPTURE_OFFSET = 3;

    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function __construct(
        private readonly string $label,
        private readonly bool $documents = false,
        private readonly bool $stack = false,
    ) {}

    public function commandStarted(CommandStartedEvent $event): void
    {
        $line = ['by' => $this->label, 'event' => 'started', 'name' => $event->getCommandName(), 'requestId' => $event->getRequestId(), 'db' => $event->getDatabaseName()];
        if ($this->documents) {
            $line['command'] = Document::fromPHP($event->getCommand())->toCanonicalExtendedJSON();
        }
        $filter = $event->getCommand()->filter ?? null;
        if (is_object($filter) && isset($filter->from) && is_string($filter->from)) {
            $line['from'] = $filter->from;
        }
        $this->events[] = $line;
    }

    public function commandSucceeded(CommandSucceededEvent $event): void
    {
        $line = ['by' => $this->label, 'event' => 'succeeded', 'name' => $event->getCommandName(), 'requestId' => $event->getRequestId(), 'micros' => $event->getDurationMicros()];
        if ($this->documents) {
            $line['reply'] = Document::fromPHP($event->getReply())->toCanonicalExtendedJSON();
        }
        if ($this->stack) {
            $line['app'] = self::applicationFrames(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'commandSucceeded');
        }
        $this->events[] = $line;
    }

    public function commandFailed(CommandFailedEvent $event): void
    {
        $line = ['by' => $this->label, 'event' => 'failed', 'name' => $event->getCommandName(), 'requestId' => $event->getRequestId(), 'code' => $event->getError()->getCode(), 'error' => get_class($event->getError())];
        if ($this->documents) {
            $line['reply'] = Document::fromPHP($event->getReply())->toCanonicalExtendedJSON();
        }
        $this->events[] = $line;
    }

    /**
     * @param list<array<string, mixed>> $trace
     *
     * @return list<array{position: int, at: string}>
     */
    private static function applicationFrames(array $trace, string $function): array
    {
        $anchor = null;
        foreach ($trace as $index => $frame) {
            if (($frame['class'] ?? null) === self::class && $frame['function'] === $function) {
                $anchor = $index;
                break;
            }
        }
        if ($anchor === null) {
            return [];
        }
        $root = dirname(__DIR__, 2);
        $vendor = (string) realpath(\Yii::getAlias('@vendor'));
        $app = [];
        for ($index = $anchor; $index < count($trace); $index++) {
            $path = $trace[$index]['file'] ?? null;
            if (!is_string($path) || str_starts_with($path, $vendor . '/') || str_starts_with($path, $root . '/src/') || $path === __FILE__) {
                continue;
            }
            $app[] = ['position' => $index - $anchor + self::CAPTURE_OFFSET, 'at' => substr($path, strlen($root) + 1) . ':' . ($trace[$index]['line'] ?? 0)];
        }

        return $app;
    }
}
