<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\sources;

use mrstroz\querymonitoring\SourceInterface;
use yii\base\InvalidConfigException;

/**
 * YQM-33: a source that takes every component of one class and either records the install or throws
 * the way a source incompatible with the installed library does. The scenario `fake-sources` prints the log.
 */
final class FakeSource implements SourceInterface
{
    /** @var list<string> `<name>:<id>` of every install() call, whatever its outcome */
    public static array $calls = [];

    /**
     * @param class-string $takes
     */
    public function __construct(
        private readonly string $name,
        private readonly string $takes,
        private readonly bool $incompatible,
    ) {}

    public function supports(object $component): bool
    {
        return $component instanceof $this->takes;
    }

    public function install(string $id, object $component): void
    {
        self::$calls[] = "{$this->name}:{$id}";
        if ($this->incompatible) {
            throw new InvalidConfigException("Source {$this->name} does not support the installed library.");
        }
    }
}
