<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

/**
 * YQM-31: the test application reaches MongoDB 7 through `yii\mongodb\Connection`.
 */
final class MongoDbEnvironmentTest extends IntegrationTestCase
{
    public function testApplicationFindsDocumentThroughMongoDbConnection(): void
    {
        $this->requireDatabase('mongodb');

        $out = $this->scenarioOutput($this->scenario(self::ANY_DB, 'mongodb-find'));

        self::assertSame(1, $out['found'], 'the inserted document is found');
    }
}
