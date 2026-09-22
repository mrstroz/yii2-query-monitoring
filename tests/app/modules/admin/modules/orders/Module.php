<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\modules\admin\modules\orders;

/**
 * Module nested in `admin`, with unique id `admin/orders`.
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = __NAMESPACE__ . '\controllers';
}
