<?php

namespace Webpatser\ResonateUserCap;

use RuntimeException;

/**
 * The user-cap key schema.
 *
 * A user's connections on one Resonate node live in
 * "{prefix}:{{appId}:{userId}}:{nodeId}", a Redis set of socket ids. Per-node
 * keys mean a dead node's count expires by TTL without a live node holding
 * it open, exactly the pattern resonate-roster uses.
 *
 * Which nodes hold a set for a user is recorded in a registry set,
 * "{prefix}:{{appId}:{userId}}:nodes", so the count never has to SCAN for the
 * per-node keys. SCAN only walks the node it is sent to, so on a Redis Cluster
 * it silently missed every key that lived elsewhere.
 *
 * The inner braces are a Redis Cluster hash tag. Cluster hashes only what sits
 * between the first "{" and the next "}", so every node's key for one user and
 * that user's registry land in the same slot, and the cap script, which reads
 * all of them in one EVAL, cannot fail with CROSSSLOT. A "}" inside a user id
 * only ends the tag early; the tag is still identical for every key of that
 * user.
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
        return $this->userKeyPrefix($appId, $userId).$node;
    }

    /**
     * Everything of a node's set key but the node id itself.
     *
     * The count appends the node ids it reads from the registry to this.
     */
    public function userKeyPrefix(string $appId, string $userId): string
    {
        return $this->userTag($appId, $userId).':';
    }

    /**
     * The set key listing the nodes that hold a set for one user on one app.
     *
     * Node ids from {@see nodeId()} always end in "-{pid}", so no node's own
     * set key can collide with this one.
     */
    public function registryKey(string $appId, string $userId): string
    {
        return $this->userTag($appId, $userId).':nodes';
    }

    /**
     * The hash-tagged part every node's key for one user shares.
     */
    protected function userTag(string $appId, string $userId): string
    {
        return $this->prefix.':{'.$appId.':'.$this->encodeIdentity($userId).'}';
    }

    /**
     * Neutralise an untrusted identity segment before it forms a key.
     *
     * The `user_id` arrives from presence `channel_data`, which is only weakly
     * constrained, so a value may carry a `:` (colliding key namespaces) or a
     * glob metacharacter (`* ? [ ] \`) that would broaden any pattern built
     * from the key. Each unsafe byte, plus `%` itself, is percent-encoded so the
     * mapping stays injective: distinct ids always yield distinct, glob-safe
     * segments. Safe ids such as "u-7" pass through unchanged.
     *
     * @throws RuntimeException when the identity could not be encoded, so a
     *                          key is never built from an unsanitised segment.
     */
    protected function encodeIdentity(string $userId): string
    {
        $encoded = preg_replace_callback(
            '/[%:*?\[\]\\\\]/',
            static fn (array $match): string => '%'.strtoupper(bin2hex($match[0])),
            $userId,
        );

        if ($encoded === null) {
            throw new RuntimeException('Unable to encode the user-cap identity segment.');
        }

        return $encoded;
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
