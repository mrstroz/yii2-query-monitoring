<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\controllers;

use mrstroz\querymonitoring\tests\app\models\Order;
use yii\web\Controller;
use yii\web\Response;

class OrderController extends Controller
{
    /**
     * Inserts one order and lists the orders of that customer through Active Record.
     */
    public function actionIndex(): Response
    {
        $order = new Order(['customer' => 'alice@example.com', 'total' => '12.50']);
        $order->save(false);

        return $this->asJson(['count' => count(Order::find()->where(['customer' => 'alice@example.com'])->all())]);
    }

    /**
     * A request that runs no query.
     */
    public function actionNone(): Response
    {
        return $this->asJson(['ok' => true]);
    }
}
