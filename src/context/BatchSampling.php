<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\context;

use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\batch\Sample;
use mrstroz\querymonitoring\batch\SampleReason;
use yii\base\InvalidConfigException;

/**
 * The `sampling` setting: which finished batches reach the adapter (spec 01 §5.6, ADR-0014).
 *
 * A batch matching an enabled criterion is always kept, with `rate` 1. Any other batch is kept when its draw is below
 * `rate`. The draw depends only on `id` and `seq`, so the same batch always gets the same decision and the choice
 * does not depend on its time, route, host or queries. {@see ContextStack} asks before every call of the adapter.
 *
 * @internal
 */
final class BatchSampling
{
    /** Keys of the setting with their defaults; `rate` has none and is required. */
    private const DEFAULTS = [
        'keepErrors' => false,
        'slowQueryMs' => null,
        'slowBatchMs' => null,
        'minQueries' => null,
    ];

    /** 2^52: the draw takes 13 hexadecimal digits of the hash, an integer a float holds exactly. */
    private const DRAW_RANGE = 4_503_599_627_370_496;

    /** @var \Closure(QueryBatch): float */
    private readonly \Closure $draw;

    private ?Sample $widest = null;

    /**
     * Values are taken as given; {@see self::fromConfig()} checks them.
     *
     * @param float $rate probability of keeping a batch that matches no criterion
     * @param float|null $slowQueryMs null disables the criterion, as do the other nulls and `false`
     * @param (\Closure(QueryBatch): float)|null $draw a number in [0, 1) for a batch no criterion keeps, called with
     *                                                the batch as built, before `sample` is set; {@see self::draw()}
     *                                                by default
     */
    public function __construct(
        private readonly float $rate,
        private readonly bool $keepErrors = false,
        private readonly ?float $slowQueryMs = null,
        private readonly ?float $slowBatchMs = null,
        private readonly ?int $minQueries = null,
        ?\Closure $draw = null,
    ) {
        $this->draw = $draw ?? static fn(QueryBatch $batch): float => self::draw($batch->id, $batch->seq);
    }

    /**
     * @param mixed $config the `sampling` setting; null disables sampling
     *
     * @throws InvalidConfigException on a value that is not an array or null, an unknown key, a missing `rate`, a value
     *                                outside spec 01 §5.6, or `rate` 0 with every criterion disabled
     */
    public static function fromConfig(mixed $config): ?self
    {
        if ($config === null) {
            return null;
        }
        if (!is_array($config)) {
            throw new InvalidConfigException('QueryMonitor::$sampling must be null or an array.');
        }
        $unknown = array_diff_key($config, self::DEFAULTS + ['rate' => null]);
        if ($unknown !== []) {
            throw new InvalidConfigException('QueryMonitor::$sampling has unknown keys: ' . implode(', ', array_keys($unknown)) . '.');
        }
        if (!array_key_exists('rate', $config)) {
            throw new InvalidConfigException('QueryMonitor::$sampling[\'rate\'] is required.');
        }
        $config = array_replace(self::DEFAULTS, $config);
        $rate = $config['rate'];
        if (!self::isNumber($rate) || $rate < 0 || $rate > 1) {
            throw new InvalidConfigException('QueryMonitor::$sampling[\'rate\'] must be a number from 0 to 1.');
        }
        if (!is_bool($config['keepErrors'])) {
            throw new InvalidConfigException('QueryMonitor::$sampling[\'keepErrors\'] must be true or false.');
        }
        foreach (['slowQueryMs', 'slowBatchMs'] as $key) {
            if ($config[$key] !== null && (!self::isNumber($config[$key]) || $config[$key] <= 0)) {
                throw new InvalidConfigException("QueryMonitor::\$sampling['{$key}'] must be null or a number above 0.");
            }
        }
        $minQueries = $config['minQueries'];
        if ($minQueries !== null && (!is_int($minQueries) || $minQueries < 1)) {
            throw new InvalidConfigException('QueryMonitor::$sampling[\'minQueries\'] must be null or an integer of at least 1.');
        }
        $sampling = new self(
            (float) $rate,
            $config['keepErrors'],
            $config['slowQueryMs'] === null ? null : (float) $config['slowQueryMs'],
            $config['slowBatchMs'] === null ? null : (float) $config['slowBatchMs'],
            $minQueries,
        );
        if ((float) $rate === 0.0 && !$sampling->hasCriterion()) {
            throw new InvalidConfigException('QueryMonitor::$sampling with rate 0 and no criterion sends nothing; use enabled: false instead.');
        }

        return $sampling;
    }

    /**
     * The `sample` a batch is sent with, or null when it is not sent: the first enabled criterion it matches in the
     * order errors, slow query, slow batch, many queries gives `rate` 1; otherwise the draw decides.
     */
    public function decide(QueryBatch $batch): ?Sample
    {
        $reason = $this->criterion($batch);
        if ($reason !== null) {
            return new Sample(1.0, $reason);
        }

        return ($this->draw)($batch) < $this->rate ? new Sample($this->rate, SampleReason::Sample) : null;
    }

    /**
     * The `sample` with the longest JSON this setting can give, for the header the collector measures (spec 02 §5).
     * Measured with the same `json_encode()`, so within one setting of `serialize_precision` a sent header is never longer. Measured once,
     * at the first batch; a `serialize_precision` changed later in the process is not taken into account.
     */
    public function widest(): Sample
    {
        if ($this->widest !== null) {
            return $this->widest;
        }
        $widest = new Sample(1.0, SampleReason::Sample);
        $bytes = 0;
        foreach (SampleReason::cases() as $reason) {
            foreach ([$this->rate, 1.0] as $rate) {
                $sample = new Sample($rate, $reason);
                $length = strlen(json_encode($sample->toArray(), QueryBatch::JSON_FLAGS));
                if ($length > $bytes) {
                    $widest = $sample;
                    $bytes = $length;
                }
            }
        }

        return $this->widest = $widest;
    }

    /**
     * A number in [0, 1) from `id` and `seq` of a batch: the first 13 hexadecimal digits of their xxh3 hash divided by
     * 2^52 (spec 01 §5.6).
     */
    public static function draw(string $id, int $seq): float
    {
        return hexdec(substr(hash('xxh3', $id . ':' . $seq), 0, 13)) / self::DRAW_RANGE;
    }

    private function criterion(QueryBatch $batch): ?SampleReason
    {
        if ($this->keepErrors && self::any($batch, static fn(QueryEntry $entry): bool => $entry->result === QueryEntry::RESULT_ERROR)) {
            return SampleReason::Error;
        }
        $slowQueryMs = $this->slowQueryMs;
        if ($slowQueryMs !== null && self::any($batch, static fn(QueryEntry $entry): bool => $entry->timeMs >= $slowQueryMs)) {
            return SampleReason::SlowQuery;
        }
        if ($this->slowBatchMs !== null && array_sum(array_map(static fn(QueryEntry $entry): float => $entry->timeMs, $batch->queries)) >= $this->slowBatchMs) {
            return SampleReason::SlowBatch;
        }
        // The queries the batch describes: in HTTP `dropped` holds those past a limit (spec 01 §5.6).
        if ($this->minQueries !== null && count($batch->queries) + $batch->dropped >= $this->minQueries) {
            return SampleReason::ManyQueries;
        }

        return null;
    }

    private function hasCriterion(): bool
    {
        return $this->keepErrors || $this->slowQueryMs !== null || $this->slowBatchMs !== null || $this->minQueries !== null;
    }

    /**
     * @param \Closure(QueryEntry): bool $test
     */
    private static function any(QueryBatch $batch, \Closure $test): bool
    {
        foreach ($batch->queries as $entry) {
            if ($test($entry)) {
                return true;
            }
        }

        return false;
    }

    private static function isNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}
