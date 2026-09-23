<?php

declare(strict_types=1);

use mrstroz\querymonitoring\tests\app\models\Contact;
use yii\data\ActiveDataProvider;
use yii\grid\GridView;

/**
 * YQM-38: the application paths of YQM-32, question 5, one per QM_CALLER_PATH, through the monitored `mongodb`.
 * One contact exists, so a found record is hydrated and a cursor of batch(1) needs a getMore.
 */
return static function (\yii\web\Application $app): array {
    $mongodb = $app->get('mongodb');
    assert($mongodb instanceof \yii\mongodb\Connection);
    $app->getAssetManager()->bundles = false;
    $collection = $mongodb->getCollection('qm_contact');
    $collection->remove();
    $collection->insert(['name' => 'qm-caller']);

    switch ((string) getenv('QM_CALLER_PATH')) {
        case 'one':
            Contact::find()->one($mongodb);
            break;
        case 'insert':
            $mongodb->getCollection('qm_caller_insert')->insert(['k' => 1]);
            break;
        case 'grid':
            GridView::widget(['dataProvider' => new ActiveDataProvider(['query' => Contact::find(), 'db' => $mongodb])]);
            break;
        case 'batch':
            foreach (Contact::find()->batch(1, $mongodb) as $_) {
            }
            break;
        case 'grid-with':
            GridView::widget(['dataProvider' => new ActiveDataProvider(['query' => Contact::find()->with('same.same'), 'db' => $mongodb])]);
            break;
    }

    return ['path' => getenv('QM_CALLER_PATH'), 'line' => __LINE__];
};
