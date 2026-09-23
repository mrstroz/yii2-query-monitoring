<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\support;

use yii\helpers\FileHelper;

/**
 * A unique path under sys_get_temp_dir() for a test that touches real files.
 *
 * The path is built here, what lives under it is created in the test, because for a filesystem test the
 * moment it starts to exist is part of the scenario. Removal is shared: it has to happen after a failed
 * assertion too, and also when the test took away its own right to write.
 *
 * The trait has two modes of use and each has its own removal. A class that creates the directory keeps it
 * in $dir and calls removeTemporaryDirectory(); a class that only composes a path and lets something else
 * create files next to it - ProcessGroupTest and the markers of processes that outlived their kill - calls
 * removeTemporaryFiles(). Both are safe when nothing was created, so tearDown() may call them either way.
 */
trait TemporaryDirectory
{
    /**
     * Left uninitialised in a class that never creates the directory. Reading it there is an error with a
     * message that names the cause, which is what a forgotten setUp() should give; removeTemporaryDirectory()
     * asks with isset() instead of reading, so it stays silent rather than throwing from tearDown().
     */
    protected string $dir;

    protected static function temporaryPath(string $prefix): string
    {
        return sys_get_temp_dir() . '/qm-' . $prefix . '-' . bin2hex(random_bytes(6));
    }

    /**
     * Removes the files a test let appear next to a path from temporaryPath(), the marker of a process that
     * outlived its kill among them. An empty path removes nothing, so a tearDown() may call this before the
     * test has composed one.
     */
    protected static function removeTemporaryFiles(string $path): void
    {
        if ($path === '') {
            return;
        }

        foreach (glob($path . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    protected function removeTemporaryDirectory(): void
    {
        if (!isset($this->dir) || !is_dir($this->dir)) {
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
