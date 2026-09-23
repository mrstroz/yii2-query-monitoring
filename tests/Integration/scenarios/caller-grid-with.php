<?php

declare(strict_types=1);

use mrstroz\querymonitoring\tests\app\models\Order;
use yii\data\ActiveDataProvider;
use yii\grid\GridView;

/**
 * The deepest application path measured for ADR-0009: a list view whose data provider eager loads relations
 * two levels deep. The query of the second level puts the first application frame at position 31, past the
 * former limit of 30 frames. Asset bundles are off, because GridView registers its JavaScript.
 */
return static function (\yii\web\Application $app): array {
    $app->getAssetManager()->bundles = false;
    $html = GridView::widget(['dataProvider' => new ActiveDataProvider(['query' => Order::find()->with('same.same')])]);

    return ['rendered' => $html !== ''];
};
