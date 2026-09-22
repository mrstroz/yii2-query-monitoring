<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\batch;

/**
 * Context a batch was collected in (spec 02 §1, field `type`).
 */
enum BatchType: string
{
    case Http = 'http';
    case Console = 'console';
}
