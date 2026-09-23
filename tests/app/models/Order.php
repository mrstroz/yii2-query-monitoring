<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property string $customer
 * @property string $total
 */
class Order extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'qm_order';
    }

    /**
     * The order itself as a relation: `with('same.same')` nests eager loading two levels deep, as a list
     * view with related records does, without a second table (ADR-0009).
     */
    public function getSame(): ActiveQuery
    {
        return $this->hasMany(self::class, ['id' => 'id']);
    }
}
