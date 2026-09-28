<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Runs `tests/Integration/scenarios/<QM_SCENARIO>.php` in the console application and prints its result as JSON.
 * The scenario gets the `\yii\console\Application`, the route is `scenario/run`.
 */
class ScenarioController extends Controller
{
    public function actionRun(): int
    {
        $name = (string) getenv('QM_SCENARIO');
        if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('QM_SCENARIO must be a file name without extension.');
        }
        $scenario = require dirname(__DIR__, 3) . "/Integration/scenarios/{$name}.php";
        echo json_encode($scenario(\Yii::$app), JSON_THROW_ON_ERROR);

        return ExitCode::OK;
    }
}
