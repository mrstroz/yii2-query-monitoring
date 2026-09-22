<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\controllers;

use yii\web\Controller;
use yii\web\Response;

/**
 * Runs `tests/Integration/scenarios/<QM_SCENARIO>.php` and returns its result as JSON.
 */
class ScenarioController extends Controller
{
    public function actionRun(): Response
    {
        $name = (string) getenv('QM_SCENARIO');
        if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('QM_SCENARIO must be a file name without extension.');
        }
        $scenario = require dirname(__DIR__, 2) . "/Integration/scenarios/{$name}.php";

        return $this->asJson($scenario(\Yii::$app));
    }
}
