<?php

namespace App\Services;

class HmacService
{
    /**
     * Derive a per-session signing key from the master key.
     *
     * The master HMAC_SIGNING_KEY never leaves the server.
     * Each session gets a unique derived key:
     *   derived_key = HMAC-SHA256(master_key, session_id)
     *
     * This means:
     *   - Compromising one session key doesn't expose the master
     *   - Each session has a unique key
     *   - Key automatically rotates when the session changes
     */
    public static function deriveSessionKey(?string $sessionId = null): ?string
    {
        $masterKey = config('hmac.signing_key');

        if (! $masterKey) {
            return null;
        }

        $sessionId = $sessionId ?? session()->getId();

        return hash_hmac('sha256', $sessionId, $masterKey);
    }

    /**
     * Verify an HMAC signature against the session-derived key.
     */
    public static function verify(string $payload, string $signature, ?string $sessionId = null): bool
    {
        $derivedKey = static::deriveSessionKey($sessionId);

        if (! $derivedKey) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $derivedKey);

        return hash_equals($expected, $signature);
    }
}
