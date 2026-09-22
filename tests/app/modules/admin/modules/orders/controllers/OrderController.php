<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\modules\admin\modules\orders\controllers;

use yii\web\Controller;
use yii\web\Response;

class OrderController extends Controller
{
    /**
     * Counts orders through the application's `db`; route `admin/orders/order/view`.
     */
    public function actionView(): Response
    {
        $app = \Yii::$app;
        assert($app !== null);

        return $this->asJson(['count' => (int) $app->getDb()->createCommand('SELECT COUNT(*) FROM qm_order')->queryScalar()]);
    }
}
