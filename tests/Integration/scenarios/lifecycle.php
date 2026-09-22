<?php

declare(strict_types=1);

/**
 * One query in the action, then queries in every hook that runs after the component's finalisation
 * could have happened, then the end chosen by QM_T1_END: `return`, `end` (Yii::$app->end()),
 * `exit`, `throw` (unhandled RuntimeException, ErrorHandler with exit(1)) or `user-throw` (a UserException,
 * the one kind the ErrorHandler hands to `errorAction` under YII_DEBUG).
 * Each hook query prints `hook:<name>=<value>` on stderr, so a test knows it ran and succeeded.
 */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';
    $db = $conn($app, 'db');
    $hook = static function (string $name, string $sql) use ($db): void {
        fwrite(STDERR, "hook:{$name}=" . $db->createCommand($sql)->queryScalar() . "\n");
    };

    $value = $db->createCommand('SELECT 801 AS qm_life_action')->queryScalar();

    $app->on(\yii\base\Application::EVENT_AFTER_REQUEST, static fn() => $hook('after_request', 'SELECT 802 AS qm_life_after_request'));
    $app->getResponse()->on(\yii\web\Response::EVENT_AFTER_SEND, static fn() => $hook('after_send', 'SELECT 803 AS qm_life_after_send'));
    register_shutdown_function(static fn() => $hook('shutdown', 'SELECT 804 AS qm_life_shutdown'));

    switch ((string) getenv('QM_T1_END')) {
        case 'end':
            $app->getResponse()->data = ['action' => (int) $value];
            $app->getResponse()->format = \yii\web\Response::FORMAT_JSON;
            $app->end();

            // under YII_ENV_TEST end() throws ExitException, caught by Application::run(); never reached
            return ['action' => 'not ended'];
        case 'exit':
            echo json_encode(['action' => (int) $value]);
            exit();
        case 'throw':
            throw new \RuntimeException('qm-t1 unhandled');
        case 'user-throw':
            throw new \yii\base\UserException('qm-t1 unhandled user error');
    }

    return ['action' => (int) $value];
};
