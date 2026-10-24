<?php

namespace Webpatser\ResonateUserCap;

use Fledge\Async\Redis\RedisClient;
use RuntimeException;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

/**
 * Cluster-wide count of a user's connections, backed by per-node Redis sets.
 *
 * Each node keeps its own set of socket ids per user, and every node holding
 * one is listed in the user's registry set. The count is the sum of the sizes
 * of the registered nodes' sets. All of a user's keys share one hash tag, so
 * every script below touches a single slot and runs unchanged on a Redis
 * Cluster. Nothing here SCANs: SCAN only walks the node it is sent to, so on
 * a Cluster it missed every key that lived on another master.
 *
 * A node registers after its set is written and unregisters only once its set
 * is empty, and a registered node whose set has expired (a node that died
 * without cleaning up) is pruned by the next cap check that reads it.
 */
class UserConnectionCounter
{
    /**
     * How often a cap check re-reads a registry that changed under it.
     */
    private const int MAX_ATTEMPTS = 5;

    /**
     * Atomically count the user's connections and conditionally SADD a socket.
     *
     * KEYS[1] is this node's set (the write target), KEYS[2] the user's node
     * registry, and KEYS[3..] the sets of the other nodes the caller read from
     * the registry just before; ARGV[5..] are those nodes' ids, in KEYS order.
     * Every key the script touches is declared, as Redis requires: an
     * undeclared key only happens to work on a Cluster while it shares the
     * declared keys' slot, and during a slot migration Redis checks only the
     * declared keys for ASK and TRYAGAIN, so an undeclared set already moved to
     * the new master would read as empty, be pruned, and under-count the user.
     *
     * The registry can gain a node between the caller's read and this script.
     * Such a node's set is not declared, so the script returns -1 instead of
     * counting without it, and the caller re-reads the registry and retries.
     * With the cap disabled the count does not matter and nothing is retried.
     *
     * A registered node whose set no longer exists has died without cleaning
     * up and its TTL ran out, so it is pruned from the registry on the way.
     *
     * The script runs as one indivisible step, so concurrent subscribes for
     * the same user are serialised by Redis: every caller re-reads the live
     * count before its own add, which closes the check-then-add race.
     *
     * Returns 1 when the socket is counted (newly added, already present, or
     * the cap is disabled), 0 when the cap is reached and the add is refused,
     * -1 when the registry holds a node the caller did not declare.
     */
    private const string ADD_IF_UNDER_CAP = <<<'LUA'
    local target = KEYS[1]
    local registry = KEYS[2]
    local socket = ARGV[2]
    local ttl = tonumber(ARGV[3])
    local node = ARGV[4]

    local function register()
        redis.call('SADD', registry, node)
        redis.call('EXPIRE', target, ttl)
        redis.call('EXPIRE', registry, ttl)
    end

    if redis.call('SISMEMBER', target, socket) == 1 then
        register()

        return 1
    end

    local cap = tonumber(ARGV[1])
    local total = redis.call('SCARD', target)
    local declared = {}

    for i = 5, #ARGV do
        declared[ARGV[i]] = KEYS[i - 2]
    end

    for _, member in ipairs(redis.call('SMEMBERS', registry)) do
        if member ~= node then
            local key = declared[member]

            if key == nil then
                if cap > 0 then
                    return -1
                end
            else
                local size = redis.call('SCARD', key)

                if size == 0 then
                    redis.call('SREM', registry, member)
                else
                    total = total + size
                end
            end
        end
    end

    if cap > 0 and total >= cap then
        return 0
    end

    redis.call('SADD', target, socket)
    register()

    return 1
    LUA;

    /**
     * Add a socket to this node's set and register the node.
     *
     * KEYS[1] is this node's set, KEYS[2] the registry. ARGV is socket, node,
     * TTL. Returns the SADD result: 1 when the socket was new.
     */
    private const string ADD = <<<'LUA'
    local added = redis.call('SADD', KEYS[1], ARGV[1])

    redis.call('SADD', KEYS[2], ARGV[2])
    redis.call('EXPIRE', KEYS[1], tonumber(ARGV[3]))
    redis.call('EXPIRE', KEYS[2], tonumber(ARGV[3]))

    return added
    LUA;

    /**
     * Remove a socket and unregister the node once its set is empty.
     *
     * One script, so an add for the same user cannot land between the size
     * check and the unregister and be left uncounted. Redis drops the set with
     * its last member, so no DEL is needed. ARGV is socket, node.
     */
    private const string REMOVE = <<<'LUA'
    redis.call('SREM', KEYS[1], ARGV[1])

    if redis.call('SCARD', KEYS[1]) == 0 then
        redis.call('SREM', KEYS[2], ARGV[2])
    end

    return 1
    LUA;

    /**
     * Replace this node's set with exactly the given sockets.
     *
     * ARGV is node, TTL, then the socket ids. With no sockets the set is
     * dropped and the node unregistered; otherwise the set is rebuilt and the
     * node registered, all in one step so no reader sees it half-written.
     * Returns 1 while sockets remain, 0 once the set is gone.
     */
    private const string SYNC = <<<'LUA'
    redis.call('DEL', KEYS[1])

    if #ARGV < 3 then
        redis.call('SREM', KEYS[2], ARGV[1])

        return 0
    end

    redis.call('SADD', KEYS[1], unpack(ARGV, 3))
    redis.call('SADD', KEYS[2], ARGV[1])
    redis.call('EXPIRE', KEYS[1], tonumber(ARGV[2]))
    redis.call('EXPIRE', KEYS[2], tonumber(ARGV[2]))

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
     *
     * Reads the registry, then every registered node's set size. The SCARDs
     * are issued together rather than one round trip after another.
     */
    public function count(string $appId, string $userId): int
    {
        $prefix = $this->keys->userKeyPrefix($appId, $userId);

        $sizes = await(array_map(
            fn (string $node) => async(fn (): int => $this->redis->getSet($prefix.$node)->getSize()),
            $this->redis->getSet($this->keys->registryKey($appId, $userId))->getAll(),
        ));

        return array_sum($sizes);
    }

    /**
     * Atomically add a socket only while the user is under the cap.
     *
     * A cap of 0 disables the limit, so the add always succeeds.
     *
     * Reads the registry first so the script can declare every node's set,
     * and re-reads it when another node registered in between.
     *
     * Returns true when the socket is counted, false when the cap is reached
     * and the connection must be rejected.
     *
     * @throws RuntimeException when the registry changed on every attempt.
     */
    public function tryAdd(string $appId, string $userId, string $socketId, int $cap): bool
    {
        [$target, $registry] = $this->keysFor($appId, $userId);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $others = array_values(array_diff($this->redis->getSet($registry)->getAll(), [$this->node]));

            $allowed = (int) $this->redis->eval(
                self::ADD_IF_UNDER_CAP,
                [$target, $registry, ...array_map(fn (string $node): string => $this->keys->userKey($appId, $userId, $node), $others)],
                [$cap, $socketId, $this->ttl, $this->node, ...$others],
            );

            if ($allowed !== -1) {
                return $allowed === 1;
            }
        }

        // The plugin treats this like any other counter failure: fail closed
        // unless fail_open is set.
        throw new RuntimeException(sprintf(
            'The user-cap node registry kept changing; gave up after %d attempts.',
            self::MAX_ATTEMPTS,
        ));
    }

    /**
     * Record a socket as a connection for this user on this node.
     *
     * Returns true if the socket was newly added, false if it was already in
     * the set (a re-subscribe of an already-counted connection).
     */
    public function add(string $appId, string $userId, string $socketId): bool
    {
        $added = $this->redis->eval(
            self::ADD,
            $this->keysFor($appId, $userId),
            [$socketId, $this->node, $this->ttl],
        );

        return (int) $added > 0;
    }

    /**
     * Remove a socket from this node's set.
     *
     * A single script with no read-then-delete: the old SREM, SCARD, DEL had
     * a window between the size check and the delete in which an add was
     * erased along with the key, silently uncounting a live connection.
     */
    public function remove(string $appId, string $userId, string $socketId): void
    {
        $this->redis->eval(
            self::REMOVE,
            $this->keysFor($appId, $userId),
            [$socketId, $this->node],
        );
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
     * what makes those ghosts self-healing. It also re-registers the node,
     * so a registry entry another node pruned is restored.
     *
     * Returns true while the user still has sockets on this node, false once
     * the list is empty and the key has been dropped.
     *
     * @param  list<string>  $socketIds
     */
    public function sync(string $appId, string $userId, array $socketIds): bool
    {
        $kept = $this->redis->eval(
            self::SYNC,
            $this->keysFor($appId, $userId),
            [$this->node, $this->ttl, ...array_values(array_unique($socketIds))],
        );

        return (int) $kept === 1;
    }

    /**
     * This node's set key and the user's registry key, in script order.
     *
     * @return list<string>
     */
    protected function keysFor(string $appId, string $userId): array
    {
        return [
            $this->keys->userKey($appId, $userId, $this->node),
            $this->keys->registryKey($appId, $userId),
        ];
    }
}
