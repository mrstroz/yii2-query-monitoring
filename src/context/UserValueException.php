<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\context;

/**
 * A value of the `user` source that spec 01 §5.7 does not accept. Its message names only the reason, never the value,
 * so the source's own {@see \mrstroz\querymonitoring\support\Guard} logs it; it extends none of the classes the
 * package's main guard trusts.
 *
 * @internal
 */
final class UserValueException extends \UnexpectedValueException {}
