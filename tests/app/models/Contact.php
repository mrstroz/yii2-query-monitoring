<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\models;

use yii\db\ActiveQueryInterface;
use yii\mongodb\ActiveRecord;

/**
 * A MongoDB Active Record of the test application, on the `mongodb` connection.
 *
 * @property \MongoDB\BSON\ObjectId $_id
 * @property string $name
 */
class Contact extends ActiveRecord
{
    public static function collectionName(): string
    {
        return 'qm_contact';
    }

    /**
     * @return list<string>
     */
    public function attributes(): array
    {
        return ['_id', 'name'];
    }

    /**
     * The contact itself as a relation: `with('same.same')` nests eager loading two levels deep, as `Order::getSame()`
     * does for SQL (ADR-0009).
     */
    public function getSame(): ActiveQueryInterface
    {
        return $this->hasMany(self::class, ['_id' => '_id']);
    }
}
