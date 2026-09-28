<?php

declare(strict_types=1);

/**
 * Runs the `<!-- example:consumer -->` block of README.md as written, with the variables it assumes supplied here:
 * `$message` (id `m-1`, attempt 2) and `$job`, whose `run()` queries `db` and, with QM_JOB_THROWS=1, then throws.
 * `SendInvoice` stays an undeclared name: `::class` does not load it. Returns what reached the caller.
 */
return static function (\yii\console\Application $app): array {
    $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/README.md');
    $at = strpos($readme, '<!-- example:consumer -->');
    if ($at === false || preg_match('/\G\s*```php\n(.*?)\n```/s', $readme, $block, 0, $at + strlen('<!-- example:consumer -->')) !== 1) {
        throw new \RuntimeException('README has no consumer example.');
    }
    $code = preg_replace('/^<\?php\s*/', '', $block[1]);

    $message = (object) ['id' => 'm-1', 'attempt' => 2];
    $job = new class {
        public function run(): void
        {
            \Yii::$app?->getDb()->createCommand('SELECT 1 AS qm_readme_consumer')->queryScalar();
            if (getenv('QM_JOB_THROWS') === '1') {
                throw new \RuntimeException('qm readme job failed');
            }
        }
    };
    $caught = null;
    try {
        (static function () use ($code, $message, $job): void {
            eval($code);
        })();
    } catch (\RuntimeException $e) {
        $caught = $e->getMessage();
    }

    return ['caught' => $caught];
};
