<?php

namespace Webpatser\ResonateUserCap;

/**
 * The user-cap key schema.
 *
 * A user's connections on one Resonate node live in
 * "{prefix}:{appId}:{userId}:{nodeId}", a Redis set of socket ids. Per-node
 * keys mean a dead node's count expires by TTL without a live node holding
 * it open, exactly the pattern resonate-roster uses.
 */
class PresenceCapKeys
{
    /**
     * Create a new key schema instance.
     */
    public function __construct(protected string $prefix = 'cap')
    {
        //
    }

    /**
     * The set key holding one node's socket ids for one user on one app.
     */
    public function userKey(string $appId, string $userId, string $node): string
    {
        return $this->prefix.':'.$appId.':'.$this->encodeIdentity($userId).':'.$node;
    }

    /**
     * The SCAN pattern matching every node's key for one user on one app.
     */
    public function userScanPattern(string $appId, string $userId): string
    {
        return $this->prefix.':'.$appId.':'.$this->encodeIdentity($userId).':*';
    }

    /**
     * Neutralise an untrusted identity segment before it forms a key.
     *
     * The `user_id` arrives from presence `channel_data`, which is only weakly
     * constrained, so a value may carry a `:` (colliding key namespaces) or a
     * glob metacharacter (`* ? [ ] \`) that would broaden a SCAN MATCH pattern.
     * Each unsafe byte, plus `%` itself, is percent-encoded so the mapping stays
     * injective: distinct ids always yield distinct, glob-safe segments. Safe
     * ids such as "u-7" pass through unchanged.
     */
    protected function encodeIdentity(string $userId): string
    {
        return preg_replace_callback(
            '/[%:*?\[\]\\\\]/',
            static fn (array $match): string => '%'.strtoupper(bin2hex($match[0])),
            $userId,
        );
    }

    /**
     * A stable, colon-free identifier for the current Resonate process.
     */
    public static function nodeId(): string
    {
        $host = gethostname() ?: 'node';

        return str_replace(':', '-', $host).'-'.getmypid();
    }
}
