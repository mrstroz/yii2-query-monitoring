<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\support;

use yii\helpers\FileHelper;

/**
 * A unique directory under sys_get_temp_dir() for a test that touches real files.
 *
 * The path is built here, the directory is created in the test, because for a filesystem test the moment
 * it starts to exist is part of the scenario. Removal is shared: it has to happen after a failed assertion
 * too, and also when the test took away its own right to write.
 */
trait TemporaryDirectory
{
    protected string $dir;

    protected static function temporaryPath(string $prefix): string
    {
        return sys_get_temp_dir() . '/qm-' . $prefix . '-' . bin2hex(random_bytes(6));
    }

    protected function removeTemporaryDirectory(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        // a test may have dropped the write permission on a directory; without it the removal fails.
        // findDirectories() lists the subdirectories only, so the root is restored on its own
        chmod($this->dir, 0o755);
        foreach (FileHelper::findDirectories($this->dir) as $directory) {
            chmod($directory, 0o755);
        }
        FileHelper::removeDirectory($this->dir);
    }
}
