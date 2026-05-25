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
        return $this->prefix.':'.$appId.':'.$userId.':'.$node;
    }

    /**
     * The SCAN pattern matching every node's key for one user on one app.
     */
    public function userScanPattern(string $appId, string $userId): string
    {
        return $this->prefix.':'.$appId.':'.$userId.':*';
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
