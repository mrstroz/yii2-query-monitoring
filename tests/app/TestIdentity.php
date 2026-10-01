<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

use yii\web\IdentityInterface;

/**
 * In-memory identity of the test application's `user` component (YQM-60): ids `42` and `'u-7'`, and the ObjectId
 * {@see self::OBJECT_ID} as a yii2-mongodb identity gives it, no database. ext-mongodb is needed only for that id.
 *
 * Every lookup counts in {@see self::$finds} and appends one line to `@runtime/find-identity.log`, so a test can see
 * whether the package loaded an identity the application did not.
 */
final class TestIdentity implements IdentityInterface
{
    public const IDS = [42, 'u-7'];

    /** Hex of the ObjectId id; `QM_SESSION_USER=oid:<this>` logs it in. */
    public const OBJECT_ID = '65f000000000000000000001';

    public static int $finds = 0;

    private function __construct(private readonly int|string|object $id) {}

    /**
     * @param mixed $id what the session stored: an int, a string or, for {@see self::OBJECT_ID}, an ObjectId
     */
    public static function findIdentity($id): ?self
    {
        self::countFind('findIdentity');
        foreach (self::IDS as $known) {
            if ((string) $known === (string) $id) {
                return new self($known);
            }
        }
        if ($id instanceof \MongoDB\BSON\ObjectId && (string) $id === self::OBJECT_ID) {
            return new self($id);
        }

        return null;
    }

    public static function findIdentityByAccessToken($token, $type = null): ?self
    {
        self::countFind('findIdentityByAccessToken');

        return null;
    }

    public function getId(): int|string|object
    {
        return $this->id;
    }

    public function getAuthKey(): ?string
    {
        return null;
    }

    public function validateAuthKey($authKey): bool
    {
        return false;
    }

    private static function countFind(string $method): void
    {
        self::$finds++;
        file_put_contents(\Yii::getAlias('@runtime') . '/find-identity.log', $method . "\n", FILE_APPEND);
    }
}
