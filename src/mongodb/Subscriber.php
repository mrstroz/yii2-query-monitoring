<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\mongodb;

use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;

/**
 * The driver's hook: hands each command event to the {@see Recorder} of its client key (spec 01 §3, ADR-0010).
 *
 * The only class of the package that needs `ext-mongodb`; {@see Source} creates it after checking the interface
 * exists. It reads the event and does nothing else. The command document is passed as a closure, so it is built
 * only when the recorder keeps the entry, and never outlives the call.
 */
final class Subscriber implements CommandSubscriber
{
    public function __construct(private readonly Recorder $recorder) {}

    public function commandStarted(CommandStartedEvent $event): void
    {
        $this->recorder->started($event->getRequestId(), $event->getCommandName(), static fn(): object => $event->getCommand());
    }

    public function commandSucceeded(CommandSucceededEvent $event): void
    {
        $this->recorder->succeeded($event->getRequestId(), $event->getDurationMicros());
    }

    public function commandFailed(CommandFailedEvent $event): void
    {
        $this->recorder->failed($event->getRequestId(), $event->getDurationMicros(), (string) $event->getError()->getCode());
    }
}
