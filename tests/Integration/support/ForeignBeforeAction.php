<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\support;

use yii\base\Application;
use yii\base\Behavior;

/**
 * Attached to the component through `QM_COMPONENT` (`"as foreignEvent"`): before routing, it triggers
 * `EVENT_BEFORE_ACTION` of the application with a plain `yii\base\Event`, as foreign code may, and
 * prints `foreign:before_action` on stderr once it has.
 *
 * @extends Behavior<\yii\base\Component>
 */
final class ForeignBeforeAction extends Behavior
{
    public function attach($owner): void
    {
        parent::attach($owner);
        $app = \Yii::$app;
        assert($app !== null);
        $app->on(Application::EVENT_BEFORE_REQUEST, static function () use ($app): void {
            $app->trigger(Application::EVENT_BEFORE_ACTION);
            fwrite(STDERR, "foreign:before_action\n");
        });
    }
}
