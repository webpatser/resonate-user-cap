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
     * Atomically count the union and conditionally SADD this node's socket.
     *
     * KEYS[1] is this node's set (always the write target and always counted);
     * KEYS[2..n] are the other nodes' sets gathered by the caller. The script
     * runs as one indivisible step, so concurrent subscribes for the same user
     * are serialised by Redis: every caller re-reads the live count before its
     * own add, which closes the check-then-add race that overshot the cap.
     *
     * Returns 1 when the socket is counted (newly added, already present, or
     * the cap is disabled), 0 when the cap is reached and the add is refused.
     */
    private const string ADD_IF_UNDER_CAP = <<<'LUA'
    local target = KEYS[1]
    local socket = ARGV[2]

    if redis.call('SISMEMBER', target, socket) == 1 then
        return 1
    end

    local cap = tonumber(ARGV[1])
    local total = 0

    for i = 1, #KEYS do
        total = total + redis.call('SCARD', KEYS[i])
    end

    if cap > 0 and total >= cap then
        return 0
    end

    redis.call('SADD', target, socket)
    redis.call('EXPIRE', target, tonumber(ARGV[3]))

    return 1
    LUA;

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
     * Atomically add a socket only while the user is under the cap.
     *
     * Gathers every node's set for the user (the local node's key is always
     * included as the write target) and hands them to a Lua script that counts
     * and conditionally adds in a single, indivisible Redis call. A cap of 0
     * disables the limit, so the add always succeeds.
     *
     * Returns true when the socket is counted, false when the cap is reached
     * and the connection must be rejected.
     */
    public function tryAdd(string $appId, string $userId, string $socketId, int $cap): bool
    {
        $target = $this->keys->userKey($appId, $userId, $this->node);

        $keys = [$target];

        foreach ($this->nodeKeys($appId, $userId) as $key) {
            if ($key !== $target) {
                $keys[] = $key;
            }
        }

        $allowed = $this->redis->eval(
            self::ADD_IF_UNDER_CAP,
            $keys,
            [$cap, $socketId, $this->ttl],
        );

        return (int) $allowed === 1;
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
     * Remove a socket from this node's set.
     *
     * A single SREM, with no follow-up delete. The old read-then-delete
     * (SREM, SCARD, DEL) had a window between the size check and the delete:
     * an add that landed in it was erased along with the key, silently
     * uncounting a live connection. The delete was never needed anyway,
     * because Redis drops a set as soon as its last member is removed.
     */
    public function remove(string $appId, string $userId, string $socketId): void
    {
        $this->redis->getSet($this->keys->userKey($appId, $userId, $this->node))->remove($socketId);
    }

    /**
     * Rebuild this node's set for a user so it holds exactly the given sockets.
     *
     * The heartbeat's authoritative pass. Anything in Redis that is no longer
     * live is removed, anything live that is missing is added, and the TTL is
     * refreshed. Incremental edits can be lost (a decrement that throws is
     * swallowed by the plugin manager), and a lost decrement used to leave a
     * stale socket id whose TTL the heartbeat then refreshed forever, holding
     * a cap slot until the node restarted. Rebuilding from the live set is
     * what makes those ghosts self-healing.
     *
     * Returns true while the user still has sockets on this node, false once
     * the list is empty and the key has been dropped.
     *
     * @param  list<string>  $socketIds
     */
    public function sync(string $appId, string $userId, array $socketIds): bool
    {
        $key = $this->keys->userKey($appId, $userId, $this->node);

        if ($socketIds === []) {
            $this->redis->delete($key);

            return false;
        }

        $set = $this->redis->getSet($key);
        $current = $set->getAll();

        $stale = array_values(array_diff($current, $socketIds));

        if ($stale !== []) {
            $set->remove(...$stale);
        }

        $missing = array_values(array_diff($socketIds, $current));

        if ($missing !== []) {
            $set->add(...$missing);
        }

        $this->redis->expireIn($key, $this->ttl);

        return true;
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
