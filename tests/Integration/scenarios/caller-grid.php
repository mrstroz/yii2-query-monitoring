<?php

declare(strict_types=1);

use mrstroz\querymonitoring\tests\app\models\Order;
use yii\data\ActiveDataProvider;
use yii\grid\GridView;

/**
 * YQM-27, application path: the counting query of an ActiveDataProvider rendered by GridView, as in every
 * list view. Called here instead of from a view file: the view adds frames only above the first
 * application frame. Asset bundles are off, because GridView registers its JavaScript.
 */
return static function (\yii\web\Application $app): array {
    $app->getAssetManager()->bundles = false;
    $html = GridView::widget(['dataProvider' => new ActiveDataProvider(['query' => Order::find()])]);

    return ['rendered' => $html !== ''];
};
