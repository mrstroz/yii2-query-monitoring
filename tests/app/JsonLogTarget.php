<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

use yii\log\Logger;
use yii\log\Target;

/**
 * Writes each log message as one JSON line `{level, category, message}` to `QM_LOG_FILE`.
 */
final class JsonLogTarget extends Target
{
    public function export(): void
    {
        $lines = '';
        foreach ($this->messages as $message) {
            [$text, $level, $category] = $message;
            $lines .= json_encode([
                'level' => Logger::getLevelName($level),
                'category' => $category,
                'message' => is_string($text) ? $text : (string) json_encode($text),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        }
        file_put_contents((string) getenv('QM_LOG_FILE'), $lines, FILE_APPEND | LOCK_EX);
    }
}
