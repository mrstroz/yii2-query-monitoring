<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\batch;

/**
 * Why a sampled batch was sent, the key `reason` of the header field `sample` (spec 02 §1, spec 01 §5.6).
 */
enum SampleReason: string
{
    /** Drawn with the probability `rate`; no criterion matched. */
    case Sample = 'sample';
    /** `keepErrors`: an entry has `result: error`. */
    case Error = 'error';
    /** `slowQueryMs`: an entry took at least the threshold. */
    case SlowQuery = 'slow_query';
    /** `slowBatchMs`: the entries of the batch took at least the threshold together. */
    case SlowBatch = 'slow_batch';
    /** `minQueries`: the batch describes at least the threshold of queries, its entries and `dropped` together. */
    case ManyQueries = 'many_queries';
}
