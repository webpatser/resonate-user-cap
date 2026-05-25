<?php

namespace Webpatser\ResonateUserCap;

use Fledge\Async\Redis\RedisClient;

/**
 * Cluster-wide count of a user's connections, backed by per-node Redis sets.
 *
 * Add/remove operate on the local node's set; the count unions every node's
 * set via SCAN + SCARD. A check-then-add against the union may occasionally
 * race two nodes into a one-over overshoot, which the next check corrects;
 * an under-cap is impossible because the union is monotone in adds.
 */
class UserConnectionCounter
{
    /**
     * Create a new counter.
     */
    public function __construct(
        protected RedisClient $redis,
        protected PresenceCapKeys $keys,
        protected string $node,
        protected int $ttl,
    ) {
        //
    }

    /**
     * The cluster-wide connection count for a user on an app.
     */
    public function count(string $appId, string $userId): int
    {
        $total = 0;

        foreach ($this->nodeKeys($appId, $userId) as $key) {
            $total += $this->redis->getSet($key)->getSize();
        }

        return $total;
    }

    /**
     * Record a socket as a connection for this user on this node.
     *
     * Returns true if the socket was newly added, false if it was already in
     * the set (a re-subscribe of an already-counted connection).
     */
    public function add(string $appId, string $userId, string $socketId): bool
    {
        $key = $this->keys->userKey($appId, $userId, $this->node);

        $added = $this->redis->getSet($key)->add($socketId) > 0;

        $this->redis->expireIn($key, $this->ttl);

        return $added;
    }

    /**
     * Remove a socket from this node's set, deleting the key when it empties.
     */
    public function remove(string $appId, string $userId, string $socketId): void
    {
        $key = $this->keys->userKey($appId, $userId, $this->node);
        $set = $this->redis->getSet($key);

        $set->remove($socketId);

        if ($set->getSize() === 0) {
            $this->redis->delete($key);
        }
    }

    /**
     * Refresh the TTL on this node's set for a user, if it still has members.
     *
     * Called from the heartbeat. Returns true while there is still anything
     * to keep alive, false once this node has nothing recorded for the user.
     */
    public function refresh(string $appId, string $userId): bool
    {
        $key = $this->keys->userKey($appId, $userId, $this->node);

        if ($this->redis->getSet($key)->getSize() === 0) {
            $this->redis->delete($key);

            return false;
        }

        $this->redis->expireIn($key, $this->ttl);

        return true;
    }

    /**
     * Every node's set key for a user on an app.
     *
     * @return list<string>
     */
    protected function nodeKeys(string $appId, string $userId): array
    {
        $keys = [];

        foreach ($this->redis->scan($this->keys->userScanPattern($appId, $userId), 100) as $key) {
            $keys[] = $key;
        }

        return $keys;
    }
}
