<?php

declare(strict_types=1);

/**
 * YQM-36: writes and a query the server answers with an error, each through `mongodb`, one collection per case,
 * so a test finds the entry by its collection. Returns what the application saw: `ok` or the exception class and code.
 * Fail points of `failCommand` (the server runs with `enableTestCommands`) add a `writeConcernError` to the next insert.
 */
return static function (\yii\web\Application $app): array {
    $mongodb = $app->get('mongodb');
    assert($mongodb instanceof \yii\mongodb\Connection);
    $seen = static function (callable $fn): string {
        try {
            $fn();

            return 'ok';
        } catch (\Throwable $e) {
            return get_class($e) . ':' . $e->getCode();
        }
    };
    $failNextInsert = static function () use ($mongodb): void {
        $mongodb->createCommand(['configureFailPoint' => 'failCommand', 'mode' => ['times' => 1], 'data' => ['failCommands' => ['insert'], 'writeConcernError' => ['code' => 64, 'errmsg' => 'qm-wce']]], 'admin')->execute();
    };
    foreach (['qm_w_dup', 'qm_w_wce', 'qm_w_both'] as $name) {
        $mongodb->getCollection($name)->remove();
    }
    $mongodb->getCollection('qm_w_dup')->insert(['_id' => 'qm-dup']);
    $mongodb->getCollection('qm_w_both')->insert(['_id' => 'qm-dup']);

    $out = [];
    $out['duplicate'] = $seen(static fn() => $mongodb->getCollection('qm_w_dup')->insert(['_id' => 'qm-dup']));
    $failNextInsert();
    $out['writeConcern'] = $seen(static fn() => $mongodb->getCollection('qm_w_wce')->insert(['k' => 1]));
    $failNextInsert();
    $out['both'] = $seen(static fn() => $mongodb->getCollection('qm_w_both')->insert(['_id' => 'qm-dup']));
    $out['rejected'] = $seen(static fn() => $mongodb->getCollection('qm_w_rejected')->find(['$qmInvalid' => 1])->toArray());

    return $out;
};
