<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\context;

use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\support\Guard;
use yii\base\InvalidConfigException;

/**
 * The `user` setting: where the header field `user` of a sent batch comes from (spec 01 §5.7, ADR-0015).
 *
 * `true` reads the identity the application already loaded in its `user` component, without loading it; a callable
 * is called with the batch. The value is read for every sent batch, after sampling kept it, and checked: an int
 * becomes its decimal string, a Stringable object its string, and a string must be valid UTF-8 and at most
 * {@see self::MAX_JSON_BYTES} bytes as JSON. Anything else, and an exception of the source, gives null through the
 * source's own guard, so a failing source never takes the one log entry of the rest of the package; that guard logs
 * the message of {@see UserValueException} only, so an exception of the source is logged by its class.
 * {@see ContextStack} asks before every call of the adapter.
 *
 * @internal
 */
final class UserSource
{
    /** Longest `user` in the batch JSON with {@see QueryBatch::JSON_FLAGS}, quotes included (spec 02 §1). */
    public const MAX_JSON_BYTES = 66;

    /** Longest `user` in bytes before encoding: an encoded string is never shorter than its bytes and two quotes. */
    private const MAX_BYTES = self::MAX_JSON_BYTES - 2;

    /**
     * @param \Closure(QueryBatch): mixed $read
     * @param Guard $guard the source's own guard, not the one of the rest of the package, trusting only
     *                    {@see UserValueException}; {@see \mrstroz\querymonitoring\QueryMonitor} passes it explicitly
     */
    public function __construct(
        private readonly \Closure $read,
        private readonly Guard $guard,
    ) {}

    /**
     * @param mixed $config the `user` setting: null, true or a callable
     *
     * @throws InvalidConfigException on any other value, including a callable PHP cannot turn into a Closure
     */
    public static function fromConfig(mixed $config, Guard $guard = new Guard([UserValueException::class])): ?self
    {
        if ($config === null) {
            return null;
        }
        if ($config === true) {
            return new self(static fn(): mixed => self::loadedIdentityId(), $guard);
        }
        try {
            // Accepts what is_callable() would only check in the current scope: a non-static method named as
            // [Class::class, 'method'] or an unknown function fails here, at bootstrap, not at every send.
            $read = \Closure::fromCallable($config);
        } catch (\TypeError) {
            throw new InvalidConfigException('QueryMonitor::$user must be null, true or a callable.');
        }

        return new self($read, $guard);
    }

    /**
     * The `user` of `$batch`, or null when the source gives none or a value spec 01 §5.7 does not accept.
     */
    public function resolve(QueryBatch $batch): ?string
    {
        return $this->guard->run(fn(): ?string => self::normalise(($this->read)($batch)), 'user');
    }

    /**
     * The widest `user` the collector reserves in the header (spec 02 §5).
     */
    public static function widest(): string
    {
        return str_repeat('x', self::MAX_BYTES);
    }

    /**
     * The id of the identity in the application's `user` component, without creating the component, renewing the
     * authentication status or opening the session; null for a guest, for an identity nothing loaded yet and for a
     * `user` component that is not a {@see \yii\web\User}.
     */
    private static function loadedIdentityId(): mixed
    {
        $app = \Yii::$app;
        if ($app === null || !$app->has('user', true)) {
            return null;
        }
        $user = $app->get('user');

        return $user instanceof \yii\web\User ? $user->getIdentity(false)?->getId() : null;
    }

    /**
     * @throws UserValueException on a value spec 01 §5.7 does not accept; the message names only the reason or the
     *                            type, never the value
     */
    private static function normalise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        // E.g. MongoDB\BSON\ObjectId, the id of a yii2-mongodb identity.
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            throw self::rejected('got ' . get_debug_type($value));
        }
        if ($value === '') {
            return null;
        }
        // Length first, so a long value is not scanned; rejected rather than cut or substituted, since two different
        // ids must not become the same one.
        if (strlen($value) > self::MAX_BYTES) {
            throw self::rejected('got a string longer than ' . self::MAX_BYTES . ' bytes');
        }
        if (preg_match('//u', $value) !== 1) {
            throw self::rejected('got an invalid UTF-8 string');
        }
        if (strlen(json_encode($value, QueryBatch::JSON_FLAGS)) > self::MAX_JSON_BYTES) {
            throw self::rejected('got a string longer than ' . self::MAX_JSON_BYTES . ' bytes as JSON');
        }

        return $value;
    }

    private static function rejected(string $reason): UserValueException
    {
        return new UserValueException(sprintf(
            'QueryMonitor::$user must give an int, null, a Stringable or a valid UTF-8 string of at most %d bytes as JSON; %s.',
            self::MAX_JSON_BYTES,
            $reason,
        ));
    }
}
