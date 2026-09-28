<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\context;

/**
 * Thrown by {@see ContextStack::beginJob()} when {@see ContextStack::MAX_DEPTH} contexts are open (spec 01 §5.1).
 *
 * The message names the limit only, never a job, so the guard logs it in full.
 */
final class ContextLimitException extends \RuntimeException {}
