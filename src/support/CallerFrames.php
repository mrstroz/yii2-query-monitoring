<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\support;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use yii\base\InvalidConfigException;

/**
 * Picks the application frames of a trace for the entry's `caller` (spec 02 §2, ADR-0009).
 *
 * An application frame has a file under the project root that is neither in Composer's vendor
 * directory nor in the package's `src/`, and is neither the entry script nor code run by `eval()`.
 * The directories are compared as prefixes computed once, never with `realpath()` per frame. Frames
 * outside the project root are skipped, so an absolute path never reaches the batch.
 */
final class CallerFrames
{
    public const MAX_FRAMES = 3;

    /**
     * What PHP appends to the file of a frame inside `eval()`'d code, e.g. `views/x.php(3) : eval()'d code`.
     * Such a frame is skipped: the trace also holds the `eval` call itself as a frame with a real file and line.
     */
    private const EVAL_MARKER = ": eval()'d code";

    private readonly string $rootPrefix;
    private readonly string $vendorPrefix;
    private readonly string $srcPrefix;

    /**
     * @param string $srcDir the package's `src/` directory, not its root: in the package's own tests the root holds `tests/`
     * @param string|null $entryScript file excluded as an exact path; null excludes none
     */
    public function __construct(string $projectRoot, string $vendorDir, string $srcDir, private readonly ?string $entryScript)
    {
        $this->rootPrefix = rtrim($projectRoot, '/') . '/';
        $this->vendorPrefix = rtrim($vendorDir, '/') . '/';
        $this->srcPrefix = rtrim($srcDir, '/') . '/';
    }

    /**
     * Directories of this process: the root package from Composer's `InstalledVersions` (a deeper
     * `vendor-dir` does not move it), vendor from where Composer loaded its `ClassLoader`, and the
     * first included file that is not the `auto_prepend_file`. Their order depends on the SAPI: the
     * CLI lists the main script before the prepended file.
     */
    public static function forProcess(): self
    {
        $root = realpath(InstalledVersions::getRootPackage()['install_path']);
        if ($root === false) {
            // InvalidConfigException, so that Guard logs the reason: the installation is the setting here.
            throw new InvalidConfigException('Composer does not report an existing project root.');
        }
        $vendorDir = dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 2);
        $prepend = (string) ini_get('auto_prepend_file');
        // An include_path-relative setting is resolved the way PHP finds the file.
        $prependPath = $prepend === '' ? false : realpath((string) (stream_resolve_include_path($prepend) ?: $prepend));
        $entryScript = null;
        foreach (get_included_files() as $file) {
            if ($file !== $prependPath) {
                $entryScript = $file;
                break;
            }
        }

        return new self($root, $vendorDir, dirname(__DIR__), $entryScript);
    }

    /**
     * Up to {@see self::MAX_FRAMES} application frames as `path:line`, nearest first, with the path
     * relative to the project root.
     *
     * @param list<array<string, mixed>> $trace from `debug_backtrace()`
     *
     * @return list<string>
     */
    public function frames(array $trace): array
    {
        $frames = [];
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;
            if (
                !is_string($file)
                || !str_starts_with($file, $this->rootPrefix)
                || str_starts_with($file, $this->vendorPrefix)
                || str_starts_with($file, $this->srcPrefix)
                || $file === $this->entryScript
                || str_contains($file, self::EVAL_MARKER)
            ) {
                continue;
            }
            $line = $frame['line'] ?? null;
            $frames[] = substr($file, strlen($this->rootPrefix)) . ':' . (is_int($line) ? $line : 0);
            if (count($frames) === self::MAX_FRAMES) {
                break;
            }
        }

        return $frames;
    }
}
