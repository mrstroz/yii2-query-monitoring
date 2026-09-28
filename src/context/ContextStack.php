<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\context;

use mrstroz\querymonitoring\adapter\BatchAdapterInterface;
use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\JobInfo;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\support\Guard;

/**
 * The open contexts of the process, the root at the bottom (spec 01 §5.1, ADR-0012).
 *
 * The one object the sources hold: every entry goes to the deepest open context, so a new batch or a new job
 * needs no change in any recorder. The stack also holds the re-entry flag of spec 01 §6 and is the only place
 * that calls the adapter, each batch inside its own guard, so a failed batch never stops the next one.
 *
 * @internal handed to the sources by {@see \mrstroz\querymonitoring\QueryMonitor::createSources()}; its methods
 * are not an extension point and may change
 */
final class ContextStack
{
    /** Most contexts open at once, the root included (spec 01 §5.1). */
    public const MAX_DEPTH = 16;

    /** @var list<Context> */
    private array $contexts = [];

    private bool $paused = false;
    private bool $finalized = false;

    /** `route` of the root, given to every job opened later (spec 02 §1). */
    private ?string $route = null;

    public function __construct(
        private readonly Guard $guard,
        private readonly BatchAdapterInterface $adapter,
        private readonly ContextSettings $settings,
    ) {}

    /**
     * Opens the root context of the process: `http` sends one batch, `console` splits once its route is known
     * (spec 01 §5.2, §5.4). Ignored when a context is already open.
     */
    public function openRoot(BatchType $type): void
    {
        if ($this->contexts !== [] || $this->finalized) {
            return;
        }
        $this->contexts[] = new Context($type, self::newId(), null, $this->settings, split: $type !== BatchType::Http, routed: false);
    }

    /**
     * Sets `route` from the entry action (spec 01 §4) for every open context and decides the root's route: excluded
     * when it matches `excludedRoutes` of the root's type (spec 01 §5.4).
     */
    public function setRoute(?string $route): void
    {
        $this->route = $route;
        foreach ($this->contexts as $context) {
            $context->setRoute($route);
        }
        if ($this->contexts !== []) {
            $root = $this->contexts[0];
            $root->markRouted($this->settings->exclusions->matches($root->type, $route), $this->emit());
        }
    }

    /**
     * Opens a job context over the deepest open context (spec 01 §5.3). The parent first sends its batch when its
     * `flushIntervalSeconds` passed. A job whose name matches `excludedRoutes['job']` is open but takes nothing.
     * Null when there is nothing to open: no root, or after finalisation.
     *
     * @param string $name the job name before truncation, as `excludedRoutes['job']` matches it
     *
     * @throws ContextLimitException when {@see self::MAX_DEPTH} contexts are open; entries stay with the deepest
     */
    public function beginJob(JobInfo $job, string $name): ?Context
    {
        $parent = $this->current();
        if ($parent === null || $this->finalized) {
            return null;
        }
        if (count($this->contexts) >= self::MAX_DEPTH) {
            throw new ContextLimitException('The context stack already holds ' . self::MAX_DEPTH . ' contexts; the job is not monitored on its own.');
        }
        // Its own guard: a failure while the parent sends must not leave this job unmonitored.
        $this->guard->run(fn() => $parent->flushIfDue($this->emit()), 'send');
        $context = new Context(
            BatchType::Job,
            self::newId(),
            $job,
            $this->settings,
            split: true,
            routed: true,
            route: $this->route,
            excluded: $this->settings->exclusions->matches(BatchType::Job, $name),
        );
        $this->contexts[] = $context;

        return $context;
    }

    /**
     * Ends `$context` and every context opened over it and still open, the deepest first, each sending its
     * remainder; its parent becomes the deepest again. A context that is not open, or the root, is left alone:
     * the root ends only with {@see self::finalize()}.
     */
    public function end(Context $context): void
    {
        $index = array_search($context, $this->contexts, true);
        if ($index === false || $index === 0) {
            return;
        }
        while (count($this->contexts) > $index) {
            $ending = array_pop($this->contexts);
            $this->guard->run(fn() => $ending->end($this->emit()), 'send');
        }
    }

    /**
     * False after finalisation, while the adapter sends, with no open context and while the deepest context
     * takes no entries. The sources then skip the entry, its trace and its normalisation.
     */
    public function isAccepting(): bool
    {
        $context = $this->current();

        return !$this->paused && !$this->finalized && $context !== null && $context->isAccepting();
    }

    /**
     * Counts one entry in `dropped` of the deepest context when its batch would not store any further entry.
     * False without counting while {@see self::isAccepting()} is false.
     */
    public function dropIfFull(): bool
    {
        $context = $this->current();

        return $this->isAccepting() && $context !== null && $context->dropIfFull();
    }

    /**
     * Hands the entry to the deepest open context and sends the batches it completes.
     */
    public function add(QueryEntry $entry): void
    {
        $context = $this->current();
        if (!$this->isAccepting() || $context === null) {
            return;
        }
        $context->add($entry, $this->emit());
    }

    /**
     * Ends every open context, the deepest first, and sends each remainder (spec 01 §5.5). A failure in one
     * context does not stop the others. Nothing is taken afterwards.
     */
    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        $this->finalized = true;
        while ($this->contexts !== []) {
            $context = array_pop($this->contexts);
            $this->guard->run(fn() => $context->end($this->emit()), 'send');
        }
    }

    /** Number of open contexts, the root included. */
    public function depth(): int
    {
        return count($this->contexts);
    }

    private function current(): ?Context
    {
        return $this->contexts === [] ? null : $this->contexts[count($this->contexts) - 1];
    }

    /**
     * @return \Closure(QueryBatch): void
     */
    private function emit(): \Closure
    {
        return fn(QueryBatch $batch) => $this->send($batch);
    }

    /**
     * Sends one batch with intake paused, so queries the adapter runs are not entries of any context.
     */
    private function send(QueryBatch $batch): void
    {
        $paused = $this->paused;
        $this->paused = true;
        try {
            $this->guard->run(fn() => $this->adapter->send($batch), 'send');
        } finally {
            $this->paused = $paused;
        }
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
