<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring;

use yii\base\InvalidConfigException;

/**
 * Installs the measurement on one kind of listed connection (spec 01 §1).
 *
 * {@see QueryMonitor} hands each component from `connections` to the first source whose {@see self::supports()}
 * accepts it. Signatures carry no type of an optional extension, so a source loads without `ext-mongodb`.
 */
interface SourceInterface
{
    /**
     * Whether this source measures `$component`. Must not open a connection or load optional classes.
     */
    public function supports(object $component): bool;

    /**
     * Installs the measurement on the component listed as `$id`.
     *
     * @throws InvalidConfigException when the connection is skipped; the component logs it once and goes on
     */
    public function install(string $id, object $component): void;
}
