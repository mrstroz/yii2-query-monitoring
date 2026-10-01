<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\batch;

/**
 * The header field `sample` of a batch sent while sampling is on (spec 02 §1): the probability with which this batch
 * was sent and why. The receiver weighs the batch by `1 / rate` (spec 02 §7).
 *
 * The constructor stores values as given, like {@see JobInfo}; only
 * {@see \mrstroz\querymonitoring\context\BatchSampling} checks the range of `rate`.
 */
final class Sample
{
    public function __construct(
        public readonly float $rate,
        public readonly SampleReason $reason,
    ) {}

    /**
     * Keys in spec 02 §1 order.
     *
     * @return array{rate: float, reason: string}
     */
    public function toArray(): array
    {
        return [
            'rate' => $this->rate,
            'reason' => $this->reason->value,
        ];
    }
}
