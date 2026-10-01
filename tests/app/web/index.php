<?php

declare(strict_types=1);

/*
 * Entry script of the test application, run by AppRunner as `php tests/app/web/index.php`.
 * Plays one GET request for the route in QM_ROUTE.
 */

define('YII_DEBUG', true);
define('YII_ENV', 'test');

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['REQUEST_URI'] = '/index.php?r=' . rawurlencode((string) getenv('QM_ROUTE'));
$_GET['r'] = (string) getenv('QM_ROUTE');

// QM_SESSION_USER=<id>: the request starts logged in as that TestIdentity, through a session file and its cookie the
// application has not read yet (YQM-60). The session id is written to @runtime/session-id.txt.
$runtime = (string) getenv('QM_RUNTIME');
if ($runtime !== '') {
    @mkdir($runtime . '/sessions');
    $sessionUser = (string) getenv('QM_SESSION_USER');
    if ($sessionUser !== '') {
        $sessionId = 'qmtestsession0001';
        $id = match (true) {
            ctype_digit($sessionUser) => (int) $sessionUser,
            // oid:<hex>: the id is an ObjectId, as yii2-mongodb identities store it.
            str_starts_with($sessionUser, 'oid:') => new MongoDB\BSON\ObjectId(substr($sessionUser, 4)),
            default => $sessionUser,
        };
        file_put_contents($runtime . '/sessions/sess_' . $sessionId, '__id|' . serialize($id));
        file_put_contents($runtime . '/session-id.txt', $sessionId);
        $_COOKIE[session_name()] = $sessionId;
    }
}

(new yii\web\Application(require dirname(__DIR__) . '/config.php'))->run();
