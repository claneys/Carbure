<?php

/**
 * MfaChallenge.php
 *
 * Token of the second step of a login (two-factor authentication): given after the
 * password, it only lets its holder send the code of his authenticator app, within
 * 5 minutes. A SignedToken of its own purpose: never accepted as a JWT (and a JWT never
 * as a challenge).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

final class MfaChallenge {

    /** Lifetime of a challenge (seconds) */
    public const LIFETIME = 300;

    /** Purpose of the token, in its payload and in its signing key */
    private const PURPOSE = 'mfa-challenge';

    /**
     * A new challenge for a user whose password was just checked.
     *
     * @param int      $userId The user
     * @param int|null $now    Unix time (now by default; tests)
     * @return array{token: string, exp: int} The token and its expiry (Unix time)
     */
    public static function create(int $userId, ?int $now = null): array
    {
        return SignedToken::create(self::PURPOSE, ['sub' => $userId], self::LIFETIME, $now);
    }

    /**
     * The user of a challenge, if it is valid: signed for this purpose, not expired.
     *
     * @param string   $token The challenge
     * @param int|null $now   Unix time (now by default; tests)
     * @return int|null The user, null if the challenge is not valid
     */
    public static function userId(string $token, ?int $now = null): ?int
    {
        $data = SignedToken::read(self::PURPOSE, $token, $now);
        return isset($data['sub']) ? (int)$data['sub'] : null;
    }
}
