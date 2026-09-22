<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\adapter;

/**
 * A file problem of {@see FileAdapter}: its message names the operation, the path and the PHP error
 * text, never the batch or a query. That is why {@see \mrstroz\querymonitoring\support\Guard} logs the
 * message of this class, unlike the message of any other adapter's exception (spec 03 §2).
 */
final class FileAdapterException extends \RuntimeException {}
