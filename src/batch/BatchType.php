<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\batch;

/**
 * Type of the context a batch was collected in (spec 02 §1, field `type`; spec 01 §5.1).
 */
enum BatchType: string
{
    case Http = 'http';
    case Console = 'console';
    case Job = 'job';
}
