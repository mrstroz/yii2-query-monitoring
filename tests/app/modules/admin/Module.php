<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\modules\admin;

/**
 * Module with its own `db` component, reachable as `admin/db`.
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = __NAMESPACE__ . '\controllers';
}
