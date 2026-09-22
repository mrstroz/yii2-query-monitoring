<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\modules\admin;

/**
 * Module with its own `db` component, reachable as `admin/db`, and the nested module `admin/orders`.
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = __NAMESPACE__ . '\controllers';

    public function init(): void
    {
        parent::init();
        $this->setModule('orders', ['class' => modules\orders\Module::class]);
    }
}
