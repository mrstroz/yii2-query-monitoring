<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\queue;

use mrstroz\querymonitoring\JobHandle;
use mrstroz\querymonitoring\QueryMonitor;
use mrstroz\querymonitoring\support\Guard;
use yii\base\Behavior;
use yii\base\Event;
use yii\queue\cli\Queue as CliQueue;
use yii\queue\ExecEvent;
use yii\queue\Queue;

/**
 * Marks the boundaries of each job of a `yiisoft/yii2-queue` component with {@see QueryMonitor::beginJob()} and
 * {@see QueryMonitor::endJob()} (spec 01 §5.3). Optional: the package loads it only when the application attaches it.
 *
 * ```php
 * 'queue' => [
 *     'class' => \yii\queue\db\Queue::class,
 *     'as queryMonitor' => ['class' => \mrstroz\querymonitoring\queue\JobMonitorBehavior::class, 'queueName' => 'queue'],
 * ],
 * ```
 *
 * A job is opened in `EVENT_BEFORE_EXEC` and ended in `EVENT_AFTER_EXEC` or `EVENT_AFTER_ERROR` of the same event
 * object, so an end without a start (the listener's `handleError()` after a child process failed in `isolate` mode)
 * does nothing. A job another handler marks `handled` gets no end event; the behavior keeps every open handle and
 * ends what is left in `EVENT_WORKER_LOOP` and `EVENT_WORKER_STOP`, and in a process without a worker loop
 * (`queue/exec`) the finalisation of the process ends it. Nothing here ever throws into the queue.
 */
final class JobMonitorBehavior extends Behavior
{
    /** Id of the {@see QueryMonitor} component, or the component itself. */
    public string|QueryMonitor $monitor = 'queryMonitor';

    /** Name written as `job.queue`; the queue component does not know its own id. */
    public ?string $queueName = null;

    /** @var array<int, JobHandle> handles opened here and not ended yet, by object id, in order of opening */
    private array $open = [];

    /** @var \WeakMap<object, JobHandle> the handle of each exec event */
    private \WeakMap $handles;

    /** Whether the wrong `$monitor` was logged in this process. */
    private static bool $logged = false;

    public function __construct($config = [])
    {
        $this->handles = new \WeakMap();
        parent::__construct($config);
    }

    /**
     * @return array<string, string>
     */
    public function events(): array
    {
        return [
            Queue::EVENT_BEFORE_EXEC => 'beforeExec',
            Queue::EVENT_AFTER_EXEC => 'afterExec',
            Queue::EVENT_AFTER_ERROR => 'afterExec',
            CliQueue::EVENT_WORKER_LOOP => 'endOpen',
            CliQueue::EVENT_WORKER_STOP => 'endOpen',
        ];
    }

    /**
     * Opens the job of `$event`: its class name, the queue name, the message id and the attempt number.
     */
    public function beforeExec(Event $event): void
    {
        $monitor = $this->resolveMonitor();
        if ($monitor === null || !$event instanceof ExecEvent) {
            return;
        }
        // Handles the package already ended, e.g. after `handled` in a queue without a worker loop (sync).
        foreach ($this->open as $key => $open) {
            if (!$open->isActive()) {
                unset($this->open[$key]);
            }
        }
        $job = $event->job;
        // Both are untyped properties in yii2-queue, typed only by PHPDoc; a driver may set another type, and
        // that must not become a TypeError in the queue. In `isolate` mode `queue/exec` passes the attempt from its
        // command line, as text.
        /** @var mixed $id */
        $id = $event->id;
        /** @var mixed $attempt */
        $attempt = $event->attempt;
        $handle = $monitor->beginJob(
            is_object($job) ? $job::class : '',
            $this->queueName,
            is_string($id) || is_int($id) ? $id : null,
            is_int($attempt) ? $attempt : (is_string($attempt) ? (filter_var($attempt, FILTER_VALIDATE_INT) ?: null) : null),
            // The event lives until handleMessage() returns, also when another handler marks it `handled`.
            $event,
        );
        if ($handle->isActive()) {
            $this->handles[$event] = $handle;
            $this->open[spl_object_id($handle)] = $handle;
        }
    }

    /**
     * Ends the job opened for this same event object, after success or error.
     */
    public function afterExec(Event $event): void
    {
        $handle = $this->handles[$event] ?? null;
        if ($handle === null) {
            return;
        }
        unset($this->handles[$event], $this->open[spl_object_id($handle)]);
        $this->resolveMonitor()?->endJob($handle);
    }

    /**
     * Ends every job opened here that got no end event and whose exec event is gone, the latest first. A job still
     * running keeps its event alive in `Queue::handleMessage()`, so a worker loop run inside it (the job processes
     * this same queue) does not end it.
     */
    public function endOpen(): void
    {
        $running = [];
        foreach ($this->handles as $handle) {
            $running[spl_object_id($handle)] = true;
        }
        $monitor = $this->resolveMonitor();
        foreach (array_reverse($this->open, true) as $key => $handle) {
            if (!isset($running[$key])) {
                unset($this->open[$key]);
                $monitor?->endJob($handle);
            }
        }
    }

    private function resolveMonitor(): ?QueryMonitor
    {
        if ($this->monitor instanceof QueryMonitor) {
            return $this->monitor;
        }
        try {
            $monitor = \Yii::$app?->get($this->monitor, false);
        } catch (\Throwable) {
            $monitor = null;
        }
        if ($monitor instanceof QueryMonitor) {
            return $monitor;
        }
        if (!self::$logged) {
            self::$logged = true;
            try {
                \Yii::error("JobMonitorBehavior: component {$this->monitor} is not a QueryMonitor; jobs are not monitored.", Guard::LOG_CATEGORY);
            } catch (\Throwable) {
                // Logging must not become the failure it reports.
            }
        }

        return null;
    }
}
