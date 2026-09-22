<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\models;

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
}
