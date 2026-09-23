<?php

declare(strict_types=1);

use mrstroz\querymonitoring\tests\app\models\Contact;
use mrstroz\querymonitoring\tests\app\MongoProbeSubscriber;
use yii\mongodb\Connection;

/**
 * YQM-32: the probe of open question 2, one case per request, chosen by QM_PROBE_CASE. Each case returns
 * what the question in docs/plan/05-mongodb.md ("Ustalenia do YQM-32") needs, and nothing of the package runs.
 */
return static function (\yii\web\Application $app): array {
    $connect = static fn(array $extra = []): Connection => new Connection(['dsn' => (string) getenv('QM_MONGODB_DSN')] + $extra);
    $mongodb = $app->get('mongodb');
    assert($mongodb instanceof Connection);
    $names = static fn(MongoProbeSubscriber $s, string $event = 'started'): array => array_values(array_map(
        static fn(array $e): string => $e['name'],
        array_filter($s->events, static fn(array $e): bool => $e['event'] === $event),
    ));

    switch ((string) getenv('QM_PROBE_CASE')) {
        case 'manager':
            // Q1: subscriber added in EVENT_AFTER_OPEN, a connection opened earlier, close() and open(), a double addSubscriber.
            $atOpen = new MongoProbeSubscriber('at-open');
            $managers = [];
            $mongodb->on(Connection::EVENT_AFTER_OPEN, static function ($event) use ($atOpen, &$managers): void {
                $managers[] = spl_object_id($event->sender->manager);
                $event->sender->manager->addSubscriber($atOpen);
            });
            $mongodb->getCollection('qm_probe')->findOne(['k' => 1]);
            $first = $mongodb->manager;
            $mongodb->close();
            $managerAfterClose = $mongodb->manager;
            $mongodb->getCollection('qm_probe')->findOne(['k' => 2]);
            $sameAfterReopen = $first === $mongodb->manager;

            // Opened before anyone subscribes, as by a component listed in bootstrap before the package.
            $early = $connect();
            $early->open();
            $earlyHadManager = $early->manager !== null;
            $twice = new MongoProbeSubscriber('twice');
            $early->manager->addSubscriber($twice);
            $early->manager->addSubscriber($twice);
            $early->getCollection('qm_probe')->findOne(['k' => 3]);

            return [
                'atOpen' => $names($atOpen),
                'managerOpens' => count($managers),
                'distinctManagers' => count(array_unique($managers)),
                'managerAfterClose' => $managerAfterClose,
                'sameAfterReopen' => $sameAfterReopen,
                'earlyHadManager' => $earlyHadManager,
                'twice' => $names($twice),
            ];

        case 'shared-client':
            // Q3: subscribers of one Manager and commands sent through another with the same or other options, or without client persistence.
            $a = $connect();
            $sameDsn = $connect();
            $otherOptions = $connect(['options' => ['appname' => 'qm-other']]);
            $notPersistent = $connect(['driverOptions' => ['disableClientPersistence' => true]]);
            $persistentFalse = $connect(['driverOptions' => ['disableClientPersistence' => false]]);
            $a->open();
            $onA = new MongoProbeSubscriber('a');
            $a->manager->addSubscriber($onA);
            $a->getCollection('qm_probe')->findOne(['from' => 'a']);
            $sameDsn->getCollection('qm_probe')->findOne(['from' => 'same-dsn']);
            $otherOptions->getCollection('qm_probe')->findOne(['from' => 'other-options']);
            $notPersistent->getCollection('qm_probe')->findOne(['from' => 'not-persistent']);
            $persistentFalse->getCollection('qm_probe')->findOne(['from' => 'persistence-false']);
            // Keys of options in another order: the driver keys the client by the exact arrays.
            $ordered = $connect(['options' => ['appname' => 'qm-order', 'connectTimeoutMS' => 5000]]);
            $reordered = $connect(['options' => ['connectTimeoutMS' => 5000, 'appname' => 'qm-order']]);
            $ordered->open();
            $onOrdered = new MongoProbeSubscriber('ordered');
            $ordered->manager->addSubscriber($onOrdered);
            $reordered->getCollection('qm_probe')->findOne(['from' => 'reordered']);

            // Two connections on one client of their own (appname): only B is used in the request. A subscriber
            // added only in A's EVENT_AFTER_OPEN sees nothing; one object added in the EVENT_AFTER_OPEN of each sees B once.
            $lazyA = $connect(['options' => ['appname' => 'qm-lazy']]);
            $lazyB = $connect(['options' => ['appname' => 'qm-lazy']]);
            $onlyA = new MongoProbeSubscriber('only-a');
            $shared = new MongoProbeSubscriber('shared');
            $lazyA->on(Connection::EVENT_AFTER_OPEN, static fn($event) => $event->sender->manager->addSubscriber($onlyA));
            foreach ([$lazyA, $lazyB] as $connection) {
                $connection->on(Connection::EVENT_AFTER_OPEN, static fn($event) => $event->sender->manager->addSubscriber($shared));
            }
            $lazyB->getCollection('qm_probe')->findOne(['from' => 'lazy-b']);

            return ['seenByA' => $onA->events, 'ordered' => $onOrdered->events, 'onlyA' => $onlyA->events, 'shared' => $shared->events, 'lazyAOpened' => $lazyA->manager !== null];

        case 'documents':
            // Q4: command documents of what yii2-mongodb sends, as canonical Extended JSON.
            $mongodb->open();
            $probe = new MongoProbeSubscriber('documents', documents: true);
            $mongodb->manager->addSubscriber($probe);
            $collection = $mongodb->getCollection('qm_probe_docs');
            $collection->remove();
            $collection->batchInsert([['k' => 1, 'tags' => ['a', 'b']], ['k' => 2, 'tags' => ['c']], ['k' => 3]]);
            (new \yii\mongodb\Query())->from('qm_probe_docs')->where(['k' => [1, 2], 'tags' => 'a'])->orderBy(['k' => SORT_DESC])->limit(5)->offset(1)->all($mongodb);
            $collection->update(['k' => 1], ['$set' => ['v' => 'x']]);
            $collection->remove(['k' => 3]);
            $collection->aggregate([['$match' => ['k' => ['$gt' => 0]]], ['$group' => ['_id' => '$k', 'n' => ['$sum' => 1]]]]);
            $collection->count(['k' => ['$gte' => 1]]);
            foreach ((new \yii\mongodb\Query())->from('qm_probe_docs')->batch(1, $mongodb) as $_) {
            }
            $collection->findAndModify(['k' => 2], ['$set' => ['v' => 'y']]);
            // A cursor left before its end: the driver sends killCursors when it is destroyed.
            $cursor = $collection->find([], [], ['batchSize' => 1]);
            foreach ($cursor as $_) {
                break;
            }
            unset($cursor);
            Contact::find()->where(['_id' => new \MongoDB\BSON\ObjectId()])->one($mongodb);

            return ['events' => $probe->events];

        case 'stack':
            // Q5: stack positions of the first application frame at the end event.
            $mongodb->getCollection('qm_contact')->remove();
            $probe = new MongoProbeSubscriber('stack', stack: true);
            $mongodb->manager->addSubscriber($probe);
            $app->getAssetManager()->bundles = false;
            Contact::find()->one($mongodb);
            $mongodb->getCollection('qm_contact')->insert(['name' => 'qm-stack']);
            \yii\grid\GridView::widget(['dataProvider' => new \yii\data\ActiveDataProvider(['query' => Contact::find(), 'db' => $mongodb])]);
            foreach (Contact::find()->batch(1, $mongodb) as $_) {
            }
            // Nested eager loading in a list view, the deepest SQL path of ADR-0009.
            \yii\grid\GridView::widget(['dataProvider' => new \yii\data\ActiveDataProvider(['query' => Contact::find()->with('same.same'), 'db' => $mongodb])]);

            return ['events' => $probe->events];

        case 'errors':
            // Q6: failCommand with writeConcernError, and a command the server rejects.
            $mongodb->open();
            $probe = new MongoProbeSubscriber('errors', documents: true);
            $mongodb->manager->addSubscriber($probe);
            $out = [];
            $write = static function (array $document, ?\MongoDB\Driver\WriteConcern $concern) use ($mongodb): string {
                $bulk = new \MongoDB\Driver\BulkWrite();
                $bulk->insert($document);
                try {
                    $mongodb->manager->executeBulkWrite('qm.qm_probe_errors', $bulk, $concern === null ? [] : ['writeConcern' => $concern]);

                    return 'ok';
                } catch (\Throwable $e) {
                    return get_class($e) . ': ' . $e->getMessage();
                }
            };
            $mongodb->getCollection('qm_probe_errors')->remove();
            $out['duplicate'] = [$write(['_id' => 'qm-dup'], null), $write(['_id' => 'qm-dup'], null)];
            $out['w2'] = $write(['k' => 'w2'], new \MongoDB\Driver\WriteConcern(2));
            $out['wTag'] = $write(['k' => 'tag'], new \MongoDB\Driver\WriteConcern('qmTag'));
            try {
                $mongodb->createCommand(['configureFailPoint' => 'failCommand', 'mode' => ['times' => 1], 'data' => ['failCommands' => ['insert'], 'writeConcernError' => ['code' => 64, 'errmsg' => 'qm-probe']]], 'admin')->execute();
                $out['failPoint'] = 'set';
            } catch (\Throwable $e) {
                $out['failPoint'] = get_class($e) . ': ' . $e->getMessage();
            }
            $out['afterFailPoint'] = $write(['k' => 'wce'], null);
            try {
                $mongodb->createCommand(['configureFailPoint' => 'failCommand', 'mode' => ['times' => 1], 'data' => ['failCommands' => ['insert'], 'writeConcernError' => ['code' => 64, 'errmsg' => 'qm-probe']]], 'admin')->execute();
                $out['both'] = [$write(['_id' => 'qm-dup'], null)];
            } catch (\Throwable $e) {
                $out['both'] = get_class($e) . ': ' . $e->getMessage();
            }
            try {
                $mongodb->getCollection('qm_probe_errors')->find(['$qmInvalid' => 1])->toArray();
            } catch (\Throwable $e) {
                $out['invalid'] = get_class($e) . ': ' . $e->getMessage();
            }

            return $out + ['events' => $probe->events];
    }

    throw new \InvalidArgumentException('Unknown QM_PROBE_CASE.');
};
