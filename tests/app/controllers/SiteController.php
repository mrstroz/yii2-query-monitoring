<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\controllers;

use yii\web\Controller;
use yii\web\Response;

class SiteController extends Controller
{
    /**
     * Default route, reached with pretty URLs (`QM_DB_CACHE=1`). Runs no query.
     */
    public function actionIndex(): Response
    {
        return $this->asJson(['route' => $this->action?->uniqueId]);
    }

    /**
     * Error action, used when `QM_ERROR_ACTION=1`. Runs one query, so a request that ends here
     * still produces a batch.
     */
    public function actionError(): Response
    {
        $app = \Yii::$app;
        assert($app !== null);
        $exception = $app->getErrorHandler()->exception;
        $app->getDb()->createCommand('SELECT 1 AS qm_in_error')->queryScalar();

        return $this->asJson(['error' => $exception === null ? null : $exception::class]);
    }

    /**
     * Runs `order/index` through `Yii::$app->runAction()`: the inner action queries, the outer one does not.
     */
    public function actionNested(): mixed
    {
        $app = \Yii::$app;
        assert($app !== null);

        return $app->runAction('order/index');
    }
}
