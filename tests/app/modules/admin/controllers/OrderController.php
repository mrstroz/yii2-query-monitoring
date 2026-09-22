<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\modules\admin\controllers;

use yii\web\Controller;
use yii\web\Response;

class OrderController extends Controller
{
    /**
     * Counts orders through the module's own connection.
     */
    public function actionIndex(): Response
    {
        /** @var \yii\db\Connection $db */
        $db = $this->module->get('db');

        return $this->asJson(['count' => (int) $db->createCommand('SELECT COUNT(*) FROM qm_order')->queryScalar()]);
    }
}
